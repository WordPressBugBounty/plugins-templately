<?php

namespace Templately\Utils;

use Elementor\Plugin;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Utils\Response\ResponseNormalizer;
use WP_Error;
use WP_REST_Response;
use function get_plugins;
use function is_plugin_active;

/**
 * Utility Helper for Templately
 *
 * This class contains some helper functions for easy access.
 *
 * @since 1.0.0
 */
class Helper extends Base {
	/**
	 * Check if development API should be used
	 *
	 * @return bool True if development API should be used
	 */
	public static function is_dev_api(){
		// Developer OVERRIDE: a PHP constant can't be redefined at runtime, so when the
		// override is explicitly unlocked (`templately_developer_override` option), the
		// saved developer setting takes priority over the wp-config constant. Read
		// directly (no Developer-class dependency — that module is dev-only) and OFF by
		// default, so production behaviour is unchanged (constant-only).
		if ( get_option( 'templately_developer_override', false ) ) {
			$settings = get_option( 'templately_developer_settings', [] );
			if ( is_array( $settings ) && array_key_exists( 'TEMPLATELY_DEV_API', $settings ) ) {
				return (bool) $settings['TEMPLATELY_DEV_API'];
			}
		}
		return defined( 'TEMPLATELY_DEV_API' ) && constant( 'TEMPLATELY_DEV_API' );
	}

	/**
	 * The singular post-type label for the content currently being viewed — i.e. what a
	 * conditioned header/footer/single/archive template is decorating on this request
	 * ("Post" on a single post, "Page" on a page, "Product" on a shop/product). Null when
	 * there's no meaningful post type (e.g. 404). Shared so BOTH theme-builder admin bars
	 * (Elementor `DocumentManager::filter_admin_bar_labels` + Gutenberg `AdminBar`) badge
	 * the same way — the pill reads the post type, not the template type.
	 *
	 * @return string|null
	 */
	public static function current_context_post_type_label(): ?string {
		$post_type = '';

		if ( is_singular() ) {
			$post_type = get_post_type( get_queried_object_id() );
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$post_type = 'product';
		} elseif ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			if ( is_array( $post_type ) ) {
				$post_type = reset( $post_type );
			}
		} elseif ( is_home() ) {
			$post_type = 'post';
		}

		if ( empty( $post_type ) ) {
			return null;
		}

		$object = get_post_type_object( $post_type );

		return $object ? $object->labels->singular_name : null;
	}

	/**
	 * Get installed WordPress Plugin List
	 * @return array
	 */
	public static function get_plugins() {
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return get_plugins();
	}
	public static function is_plugins_installed($plugin_file) {
		$_plugins     = self::get_plugins();
		$is_installed = isset($_plugins[$plugin_file]);
		return $is_installed;
	}

	/**
	 * Get installed WordPress Plugin List
	 * @return boolean
	 */
	public static function is_plugin_active($plugin) {
		if (! function_exists('is_plugin_active')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active($plugin);
	}

	/**
	 * Collect IP from request.
	 *
	 * Prefers REMOTE_ADDR since it cannot be spoofed by the client. When it is
	 * a private/reserved address (reverse proxy, Docker bridge gateway like
	 * 192.168.65.1, local dev), the forwarded headers are scanned for the first
	 * public IP. If nothing public is found, the request is local: 127.0.0.1.
	 *
	 * @return string
	 */
	public static function get_ip() {
		$remote_addr = ! empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';

		if (self::is_public_ip($remote_addr)) {
			return $remote_addr;
		}

		foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP'] as $header) {
			if (empty($_SERVER[$header])) {
				continue;
			}
			$candidates = explode(',', sanitize_text_field($_SERVER[$header]));
			foreach ($candidates as $candidate) {
				$candidate = trim($candidate);
				if (self::is_public_ip($candidate)) {
					return $candidate;
				}
			}
		}

		return '127.0.0.1';
	}

	/**
	 * Check whether a string is a valid public (non-private, non-reserved) IP.
	 *
	 * @param string $ip
	 * @return bool
	 */
	private static function is_public_ip($ip): bool {
		return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
	}

	/**
	 * Get views for front-end display
	 *
	 * @param string $name  it will be file name only from the view's folder.
	 * @param array $data
	 * @return void
	 */
	public static function views($name, $data = []) {
		extract($data);
		$helper = self::class;
		$file = TEMPLATELY_PATH . 'views/' . $name . '.php';

		if (is_readable($file)) {
			include_once $file;
		}
	}

	/**
	 * A URL on the public Templately website, honouring the dev domain.
	 *
	 * The PHP counterpart of `react-src/utils/helper.ts#webURL`. A hard-coded
	 * `https://templately.com/...` sends a site running against the dev API to
	 * the live site, where its account does not exist — so build every out-link
	 * through this instead.
	 *
	 * Note this is the *website*, not the API host `get_api_url()` builds.
	 *
	 * @param string $path Path with or without a leading slash.
	 * @param array  $args Query args (utm_* etc).
	 * @return string
	 */
	public static function web_url( string $path = '', array $args = [] ): string {
		$base_url = self::is_dev_api() ? 'https://templately.dev' : 'https://templately.com';
		$url      = $base_url . '/' . ltrim( $path, '/' );

		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Get API URL for Templately endpoints
	 *
	 * @param string $endpoint API endpoint path (e.g., 'v2/import/pack/123')
	 * @return string Complete API URL
	 */
	public static function get_api_url($endpoint): string {
		$base_url = self::is_dev_api() ? 'https://app.templately.dev' : 'https://app.templately.com';

		/**
		 * Filter the base URL for development API
		 *
		 * @since 3.5.0
		 * @param string $base_url The default base URL
		 */
		$base_url = apply_filters('templately_dev_api_base_url', $base_url);

		return "{$base_url}/api/{$endpoint}";
	}

	/**
	 * Resolve the requesting host platform ('templately' | 'ai-builder' | ...),
	 * forwarded to the cloud as the `x-templately-requested-platform` header so the
	 * cloud can scope behaviour per host (e.g. waive the pack-purchase gate for the
	 * AI Builder onboarding). Resolution order:
	 *   1. the incoming `X-Templately-Requested-Platform` HTTP header — a host (e.g.
	 *      ai-builder) sets it ONCE via an apiFetch middleware / fetch header, so no
	 *      per-call body param is needed;
	 *   2. the `requested_platform` request param (back-compat with explicit callers);
	 *   3. the `templately_requested_platform` filter (programmatic override);
	 *   4. the fallback (default 'templately').
	 *
	 * @param string $fallback Value when nothing else resolves.
	 * @return string
	 */
	public static function get_requested_platform( $fallback = 'templately' ) {
		$platform = '';
		if ( ! empty( $_SERVER['HTTP_X_TEMPLATELY_REQUESTED_PLATFORM'] ) ) {
			$platform = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TEMPLATELY_REQUESTED_PLATFORM'] ) );
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading a host marker, not acting on form data.
		} elseif ( isset( $_REQUEST['requested_platform'] ) ) {
			$platform = sanitize_text_field( wp_unslash( $_REQUEST['requested_platform'] ) );
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}

		if ( '' === $platform ) {
			$platform = $fallback;
		}

		return apply_filters( 'templately_requested_platform', $platform );
	}

	/**
	 * Resolve the entry-point/source marker for the current request — which surface
	 * initiated the flow (admin SPA, editor toolbar, editor add-section, …). Mirrors
	 * get_requested_platform(): incoming `X-Templately-Source` header →
	 * `$_REQUEST['templately_source']` → filter. Empty string means "unknown" and is
	 * NOT forwarded to the cloud. Values are engagement telemetry only — never branch
	 * behaviour on them.
	 *
	 * @param string $fallback Value when nothing else resolves.
	 * @return string
	 */
	public static function get_request_source( $fallback = '' ) {
		$source = '';
		if ( ! empty( $_SERVER['HTTP_X_TEMPLATELY_SOURCE'] ) ) {
			$source = sanitize_key( wp_unslash( $_SERVER['HTTP_X_TEMPLATELY_SOURCE'] ) );
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading a telemetry marker, not acting on form data.
		} elseif ( isset( $_REQUEST['templately_source'] ) ) {
			$source = sanitize_key( wp_unslash( $_REQUEST['templately_source'] ) );
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}

		if ( '' === $source ) {
			$source = $fallback;
		}

		return apply_filters( 'templately_request_source', $source );
	}

	/**
	 * Make a unified API request to Templately API
	 *
	 * @param string $method HTTP method (GET or POST)
	 * @param string $api_url Complete API URL
	 * @param array $body Request body data (for POST requests)
	 * @param array $extra_headers Additional headers beyond the standard ones
	 * @param int $timeout Request timeout in seconds (default: 30)
	 * @return array|WP_Error Response array or WP_Error on failure
	 */
	private static function make_api_request($method, $api_url, $body = [], $extra_headers = [], $timeout = 30) {
		$api_key = Options::get_instance()->get('api_key');

		$headers = [
			'Authorization'        => 'Bearer ' . $api_key,
			'x-templately-ip'      => self::get_ip(),
			'x-templately-url'     => home_url('/'),
			'x-templately-version' => defined( 'TEMPLATELY_VERSION' ) ? constant( 'TEMPLATELY_VERSION' ) : '1.0.0',
			// Force JSON responses so the cloud returns JSON errors instead of an HTML
			// error page (which json_decode() cannot parse). Binary/XML downloads
			// (zip pack, attachment WXR) use their own wp_remote_* calls and bypass
			// this helper, so they are unaffected. Callers can override via $extra_headers.
			'Accept'               => 'application/json',
		];

		// Add Content-Type for POST requests
		if (strtoupper($method) === 'POST') {
			$headers['Content-Type'] = 'application/json';
		}

		// Resolve the requesting host platform centrally. A caller-supplied extra_headers
		// value still wins (explicit override); otherwise resolve from the incoming
		// header / request param / filter (see Helper::get_requested_platform).
		if ( ! isset( $extra_headers['x-templately-requested-platform'] ) ) {
			$extra_headers['x-templately-requested-platform'] = self::get_requested_platform();
		}

		// Entry-point marker (engagement telemetry). A URL QUERY PARAM, not a header:
		// the cloud's ordinary access logs capture the request URL, so the marker is
		// countable with zero cloud-side code. No separate tracking calls are ever
		// made (wp.org policy) — this only annotates requests that happen anyway.
		// Only appended when known; absent means "unknown", not an error.
		if ( '' !== self::get_request_source() ) {
			$api_url = add_query_arg( 'tl_source', self::get_request_source(), $api_url );
		}

		// Merge additional headers
		$headers = array_merge($headers, $extra_headers);

		// A request the HTTP layer is allowed to spend $timeout seconds on can still
		// be killed by PHP's OWN max_execution_time, which is 30s on a great many
		// hosts. When that happens there is no WP_Error and no response to inspect:
		// PHP fatals mid-request, WordPress answers with its HTML "critical error"
		// page, and a JSON client renders that markup as if it were content. Give
		// PHP enough runway for the timeout we are about to ask for.
		self::extend_time_limit( $timeout );

		$args = [
			'timeout' => $timeout,
			'headers' => $headers,
		];

		// Apply filter to allow network admin or other functionality to modify request args
		$args = apply_filters( 'templately_api_request_params', $args, $method, $api_url );

		// Add body for POST requests
		if (strtoupper($method) === 'POST') {
			$args['body'] = is_array($body) ? json_encode($body) : $body;
		}

		// Make the appropriate request
		if (strtoupper($method) === 'POST') {
			$response = wp_remote_post($api_url, $args);
		} else {
			$response = wp_remote_get($api_url, $args);
		}

		// Check for verification header in the response
		self::check_verification_header($response);


		// Check for site disconnection in response body
		self::check_site_disconnection($response);

		return $response;
	}

	/**
	 * GET a Templately API endpoint and return the NORMALIZED result (spec 043).
	 *
	 * This is the REST twin of `Http::post()`. Prefer it over
	 * `make_api_get_request()`: that one hands back the raw `wp_remote_*` array and
	 * leaves every caller to invent its own "did this fail?" check — which is how
	 * the plugin ended up reading one server ten different ways.
	 *
	 * Side-effects (verification / disconnection) are already applied by
	 * `make_api_request()`, so the normalizer is told to skip them rather than
	 * repeat the work.
	 *
	 * @param string $endpoint      API endpoint path (e.g. 'v2/import/info/pack/123').
	 * @param array  $query_params  Query parameters.
	 * @param array  $extra_headers Additional headers.
	 * @param int    $timeout       Seconds.
	 * @param array  $options       Normalizer options (e.g. [ 'raw' => true ] for binary).
	 * @return \Templately\Utils\Response\RemoteResponse
	 */
	public static function api_get( $endpoint, $query_params = [], $extra_headers = [], $timeout = 30, $options = [] ) {
		return ResponseNormalizer::normalize(
			self::make_api_get_request( $endpoint, $query_params, $extra_headers, $timeout ),
			array_merge( [ 'side_effects' => false ], $options )
		);
	}

	/**
	 * POST to a Templately API endpoint and return the NORMALIZED result (spec 043).
	 *
	 * @param string $endpoint      API endpoint path.
	 * @param array  $body          Request body.
	 * @param array  $extra_headers Additional headers.
	 * @param int    $timeout       Seconds.
	 * @param array  $options       Normalizer options.
	 * @return \Templately\Utils\Response\RemoteResponse
	 */
	public static function api_post( $endpoint, $body = [], $extra_headers = [], $timeout = 30, $options = [] ) {
		return ResponseNormalizer::normalize(
			self::make_api_post_request( $endpoint, $body, $extra_headers, $timeout ),
			array_merge( [ 'side_effects' => false ], $options )
		);
	}

	/**
	 * Make a GET request to Templately API
	 *
	 * @param string $endpoint API endpoint path (e.g., 'v2/import/pack/123')
	 * @param array $query_params Query parameters as key-value pairs
	 * @param array $extra_headers Additional headers beyond the standard ones
	 * @param int $timeout Request timeout in seconds (default: 30)
	 * @return array|WP_Error Response array or WP_Error on failure
	 */
	public static function make_api_get_request($endpoint, $query_params = [], $extra_headers = [], $timeout = 30) {
		$api_url = self::get_api_url($endpoint);

		// Add query parameters if provided.
		//
		// `http_build_query()`, NOT `add_query_arg()`: the latter does not encode the values
		// it is given (only the ones already on the URL, via its own `urlencode_deep`), so a
		// value carrying `&` silently became two parameters and a value carrying `#` truncated
		// the rest of the query into a fragment the server never sees. Every param here comes
		// from somewhere a user can type — an image search term, a business description, a hex
		// colour — so that is not a theoretical shape. It also flattens arrays as `k[0]=…`,
		// which is what PHP on the other end parses back into an array.
		if (!empty($query_params)) {
			$separator = false === strpos($api_url, '?') ? '?' : '&';
			$api_url  .= $separator . http_build_query($query_params, '', '&', PHP_QUERY_RFC3986);
		}

		return self::make_api_request('GET', $api_url, [], $extra_headers, $timeout);
	}

	/**
	 * Make a POST request to Templately API
	 *
	 * @param string $endpoint API endpoint path (e.g., 'v2/feedback/store')
	 * @param array $body Request body data
	 * @param array $extra_headers Additional headers beyond the standard ones
	 * @param int $timeout Request timeout in seconds (default: 30)
	 * @return array|WP_Error Response array or WP_Error on failure
	 */
	public static function make_api_post_request($endpoint, $body = [], $extra_headers = [], $timeout = 30) {
		$api_url = self::get_api_url($endpoint);
		return self::make_api_request('POST', $api_url, $body, $extra_headers, $timeout);
	}

	/**
	 * Ensure PHP will not cut the process short before an HTTP call of $timeout
	 * seconds can finish.
	 *
	 * Only ever RAISES the limit, and only when the configured one is too small;
	 * a host that has already granted more keeps it. `max_execution_time` of 0
	 * means "no limit" (CLI, WP-Cron on some setups) and needs nothing. When the
	 * host has disabled `set_time_limit()`, `function_exists()` is false and we
	 * leave the request to take its chances rather than emit a warning.
	 *
	 * @param int $timeout Seconds the pending HTTP request may take.
	 * @return void
	 */
	private static function extend_time_limit( $timeout ) {
		$limit = (int) ini_get( 'max_execution_time' );

		if ( $limit <= 0 || ! function_exists( 'set_time_limit' ) ) {
			return;
		}

		// The margin covers everything around the call that also runs on this
		// clock: building the payload, and the response handling afterwards.
		$needed = (int) $timeout + 30;

		if ( $needed > $limit ) {
			@set_time_limit( $needed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- host may refuse; the request still proceeds.
		}
	}

	/**
	 * Sanitize Helper
	 *
	 * @param mixed $value
	 * @param string $type
	 *
	 * @return bool|string
	 */
	public static function sanitize($value, $type = 'text') {
		switch ($type) {
			case 'boolean':
				$sanitized_value = rest_sanitize_boolean($value);
				break;
			default:
				$sanitized_value = sanitize_text_field($value);
				break;
		}

		return $sanitized_value;
	}

	/**
	 * Escape a string for safe embedding inside a GraphQL or JSON string literal.
	 *
	 * GraphQL string escaping rules are identical to JSON string escaping (per the
	 * GraphQL spec), so wp_json_encode() is the authoritative escaper. We strip the
	 * outer quotes it adds and return only the escaped inner content, ready to be
	 * wrapped in your own quote pair.
	 *
	 * Handles pre-encoded JSON: when the caller has already run json_encode() +
	 * wp_slash() on a value (e.g. categories, dependencies in Items.php), the
	 * quotes are already escaped as \" and the string is ready to embed. Calling
	 * wp_json_encode() again would double-escape those backslashes. We detect this
	 * case by checking whether wp_unslash() produces valid JSON, and if so, return
	 * the value directly without further encoding.
	 *
	 * @param  string $value Raw string or wp_slash(json_encode()) output.
	 * @return string Escaped string, safe to place between double quotes in GraphQL/JSON.
	 */
	public static function esc_json_string( $value ) {
		$value = (string) $value;

		// If wp_slash() was applied to a JSON string upstream, the quotes are
		// already escaped (e.g. {\"key\":\"val\"}). Detect this by unslashing and
		// checking for valid JSON — if it matches, the value is already suitable
		// for embedding in a string literal; return it as-is to avoid doubling backslashes.
		$unslashed = wp_unslash( $value );
		if ( $unslashed !== $value ) {
			$decoded = json_decode( $unslashed, true );
			if ( json_last_error() === JSON_ERROR_NONE && null !== $decoded ) {
				return $value;
			}
		}

		$encoded = wp_json_encode( $value );
		// wp_json_encode wraps the value in "...", strip those outer quotes.
		return substr( $encoded, 1, -1 );
	}

	/**
	 * Flip the stored user's `is_verified` flag on.
	 *
	 * Extracted for spec 043 so the normalizer can apply the same side-effect
	 * when verification arrives in the response BODY (the connect mutation)
	 * rather than in the `X-Templately-Verified` header.
	 *
	 * @return array the (possibly updated) user option; empty when no user is stored.
	 */
	public static function mark_user_verified() {
		$options = Options::get_instance();
		$user    = $options->get('user');

		if (empty($user) || !is_array($user)) {
			return [];
		}

		if (empty($user['is_verified'])) {
			$user['is_verified'] = true;
			$options->set('user', $user);
		}

		return $user;
	}

	/**
	 * Check for X-Templately-Verified header and update user verification status
	 *
	 * @param array|WP_Error $response The HTTP response array from wp_remote_get/wp_remote_post
	 * @return void
	 */
	public static function check_verification_header($response) {
		// Only process if response is not a WP_Error and contains headers
		if (is_wp_error($response)) {
			return;
		}

		// Retrieve the X-Templately-Verified header
		$verification_header = wp_remote_retrieve_header($response, 'X-Templately-Verified');

		// Check if header exists and has a truthy value.
		// An empty/absent header means "no change" — never "unverified" (043 D6).
		if (!empty($verification_header) && filter_var($verification_header, FILTER_VALIDATE_BOOLEAN)) {
			try {
				$user = self::mark_user_verified();

				if (!empty($user['is_verified'])){
					if(!headers_sent()){
						header( 'X-Templately-Verified: true' );
						if (defined('TEMPLATELY_DEBUG_LOG') && constant('TEMPLATELY_DEBUG_LOG')) {
							self::log('User verification status already updated via X-Templately-Verified header');
						}
					}

					return true;
				}
			} catch (\Exception $e) {
				// Log error if debug logging is enabled
				if (defined('TEMPLATELY_DEBUG_LOG') && constant('TEMPLATELY_DEBUG_LOG')) {
					self::log('Error updating user verification status: ' . $e->getMessage());
				}
			}
		}

		return false;
	}

	/**
	 * Check for site disconnection status in API response body
	 *
	 * Detects SiteNotConnected errors and updates user disconnection status.
	 * Sends X-Templately-Disconnected header for frontend detection.
	 *
	 *
	 * @param array|WP_Error|mixed $response The response object or body array
	 * @return bool True if site is disconnected, false otherwise
	 */
	public static function check_site_disconnection($response) {
		if (is_wp_error($response)) {
			return false;
		}

		$response_body = $response;

		// If it's a raw WP response array with body, decode it
		if (is_array($response) && isset($response['body']) && is_string($response['body'])) {
			$response_body = json_decode(wp_remote_retrieve_body($response), true);
		}

		// Check if response body indicates site disconnection
		if (!is_array($response_body)) {
			return false;
		}

		$status = $response_body['status'] ?? null;
		$status_text = $response_body['statusText'] ?? null;

		// Check for SiteNotConnected error
		if ($status === 'error' && $status_text === 'SiteNotConnected') {
			try {
				// Get current user data
				$options = Options::get_instance();
				$user = $options->get('user');

				// Only update if user data exists
				if (!empty($user) && is_array($user)) {
					// Set disconnection flag
					$user['is_disconnected'] = true;

					// Save updated user data
					$options->set('user', $user);

					if (defined('TEMPLATELY_DEBUG_LOG') && constant('TEMPLATELY_DEBUG_LOG')) {
						self::log('Site disconnection detected: SiteNotConnected status');
					}
				}

				// Send header for frontend detection
				if (!headers_sent()) {
					header('X-Templately-Disconnected: true');
				}

				return true;
			} catch (\Exception $e) {
				// Log error if debug logging is enabled
				if (defined('TEMPLATELY_DEBUG_LOG') && constant('TEMPLATELY_DEBUG_LOG')) {
					self::log('Error updating site disconnection status: ' . $e->getMessage());
				}
			}
		}

		return false;
	}

	/**
	 * Clear site disconnection status
	 *
	 * Called after successful site migration to reset the disconnection flag.
	 *
	 * @return void
	 */
	public static function clear_site_disconnection() {
		try {
			$options = Options::get_instance();
			$user = $options->get('user');

			if (!empty($user) && is_array($user)) {
				$user['site_url']        = base64_encode( home_url('/') );
				$user['is_disconnected'] = false;
				$options->set('user', $user);

				if (defined('TEMPLATELY_DEBUG_LOG') && constant('TEMPLATELY_DEBUG_LOG')) {
					self::log('Site disconnection status cleared and URL updated.');
				}
			}
		} catch (\Exception $e) {
			if (defined('TEMPLATELY_DEBUG_LOG') && constant('TEMPLATELY_DEBUG_LOG')) {
				self::log('Error clearing site disconnection status: ' . $e->getMessage());
			}
		}
	}

	/**
	 * API Error Formatter
	 *
	 * @param int $error_code
	 * @param mixed $error_message
	 * @param string $endpoint
	 * @param integer $status
	 * @param array $additional_data
	 * @return WP_Error
	 */
	public static function error($error_code, $error_message, $endpoint = '', $status = 500, $additional_data = []) {
		// The status reaches WP's REST server as `data.status` and decides the HTTP
		// code. A STRING there ("404") makes WP fall back to 500, so a caller that
		// passed a numeric string got the wrong status on the wire.
		$additional_data['status'] = (int) $status;

		// An array message is field-level validation detail, not a sentence. Left in
		// `message` it renders as "Array" to the user; routed to `fields` it is
		// structured data the client can attach to the right input (FR-007).
		if (is_array($error_message)) {
			$additional_data['fields'] = array_merge(
				isset($additional_data['fields']) && is_array($additional_data['fields']) ? $additional_data['fields'] : [],
				$error_message
			);
			$error_message = __('Please correct the highlighted fields.', 'templately');
		}
		if (! empty($endpoint)) {
			$additional_data['endpoint'] = $endpoint;
		}
		// Small-response browser padding is NOT added here any more (043 FR-008a).
		// It used to staple 512 bytes into every error's data bag unconditionally,
		// which put a junk field inside the response contract and padded bodies
		// that were never small enough to need it. `RestEnvelope` now applies it
		// once, centrally, only below the size threshold, and as a header — so the
		// body stays exactly the shape the schema describes.
		return new WP_Error($error_code, $error_message, $additional_data);
	}

	/**
	 * API Response Formatter
	 *
	 * @param mixed $data
	 * @return WP_REST_Response
	 */
	public static function success($data) {
		return new WP_REST_Response($data, 200);
	}

	/**
	 * Normalize Favourites Data
	 *
	 * @param array $favourites
	 * @param array $_favourites
	 * @param boolean $undo
	 *
	 * @return array
	 */
	public function normalizeFavourites($favourites, $_favourites = [], $undo = false) {
		if ($undo) {
			$_favourites[$favourites['type']] = array_values(array_filter($_favourites[$favourites['type']], function ($item) use ($favourites) {
				return $item != $favourites['id'];
			}));
			return $_favourites;
		}

		array_map(function ($item) use (&$_favourites) {
			if (! is_null($item)) {
				$item = (array) $item;
				if (isset($_favourites[$item['type']])) {
					$_favourites[$item['type']][] = $item['id'];
				} else {
					$_favourites[$item['type']] = [$item['id']];
				}
			}
			return $_favourites;
		}, $favourites);

		return $_favourites;
	}

	public function normalizeReviews($favourites, $_favourites = [], $undo = false) {
		array_map(function ($item) use (&$_favourites) {
			if (! is_null($item)) {
				$item = (array) $item;
				if (!isset($_favourites[$item['type']])) {
					$_favourites[$item['type']] = [];
				}
				$_favourites[$item['type']][$item['type_id']] = $item['rating'];
			}
			return $_favourites;
		}, $favourites);

		return $_favourites;
	}

	/**
	 * Trigger Error
	 *
	 * @param object $triggered_by
	 * @return void
	 */
	public static function trigger_error($triggered_by, $method = 'get_instance') {
		$class = get_class($triggered_by);
		$trace = debug_backtrace(); // phpcs:ignore PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.NeedsInspection
		$file = $trace[0]['file'];
		$line = $trace[0]['line'];
		trigger_error( esc_html( "Call to undefined method $class::$method() in $file on line $line" ), E_USER_ERROR ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
	}

	/**
	 * Write a line to Templately's dedicated log file (uploads/templately/log/),
	 * NOT the site-wide debug.log — see {@see \Templately\Utils\Log\LogFile}.
	 *
	 * @param mixed $log The data to log
	 * @param string $context Optional context for categorizing log entries
	 * @param string $level Optional log level (debug, info, warning, error)
	 * @return void
	 */
	public static function log($log, $context = '', $level = 'info') {
		// Allow complete override of logging behavior
		$override_result = apply_filters('templately_log_override', null, $log, $context, $level);
		if ($override_result !== null) {
			return;
		}

		// The default sink is WP_DEBUG_LOG-gated; additional sinks subscribed to
		// the `templately_log` action see entries regardless (a capture module
		// must not depend on the file gate). Skip the formatting work entirely
		// when nobody would receive the entry.
		$gate_open = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
		if (!$gate_open && !has_action('templately_log')) {
			return;
		}

		// Format the log message
		$formatted_message = self::format_log_message($log, $context, $level);

		/**
		 * Fan-out: every subscribed module receives every entry (http-inspector
		 * capture, dev console, telemetry…). Listeners are independent sinks —
		 * this is how MULTIPLE loggers coexist without replacing each other.
		 *
		 * @param string $formatted_message The full formatted line.
		 * @param mixed  $log               The raw payload passed to log().
		 * @param string $context           Context label.
		 * @param string $level             debug|info|warning|error.
		 */
		do_action('templately_log', $formatted_message, $log, $context, $level);

		if (!$gate_open) {
			return;
		}

		/**
		 * The DEFAULT sink, replaceable/decoratable: a filter receives the
		 * current sink callable and may return a wrapper around it (decorator —
		 * filter priority defines wrap order) or a substitute. Core's default is
		 * the dedicated uploads log file (error_log fallback inside). Note:
		 * entries logged before a module boots (e.g. Modules_Manager discovery
		 * notices) necessarily use the core default.
		 *
		 * @param callable $sink function( string $formatted_line ): void
		 */
		$sink = apply_filters('templately_log_sink', [Log\LogFile::class, 'write']);
		if (is_callable($sink)) {
			$sink($formatted_message);
		} else {
			Log\LogFile::write($formatted_message);
		}
	}

	/**
	 * Format log message with context and level
	 *
	 * @param mixed $log The data to log
	 * @param string $context Context for categorizing log entries
	 * @param string $level Log level
	 * @return string Formatted log message
	 */
	private static function format_log_message($log, $context = '', $level = 'info') {
		// Convert arrays and objects to readable format
		if (is_array($log) || is_object($log)) {
			$log_content = print_r($log, true);
		} else {
			$log_content = (string) ($log ?: '');
		}

		// Build the formatted message
		$timestamp = current_time('Y-m-d H:i:s');
		$level_upper = strtoupper($level);

		if (!empty($context)) {
			return "[{$timestamp}] [{$level_upper}] [{$context}] {$log_content}";
		} else {
			return "[{$timestamp}] [{$level_upper}] {$log_content}";
		}
	}

	public static function should_flush() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only capability probe, not a state change.
		$is_lightspeed = isset($_REQUEST['is_lightspeed']) ? sanitize_text_field(wp_unslash($_REQUEST['is_lightspeed'])) : '';
		if ('true' === $is_lightspeed) {
			return false;
		}

		// SERVER_SOFTWARE is not guaranteed to be set — some SAPIs (and CLI) omit
		// it entirely, and passing null to strpos() is a fatal on PHP 8.1+.
		$server_software = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '';

		return (!defined('TEMPLATELY_IGNORE_FLUSH_ALL') || !TEMPLATELY_IGNORE_FLUSH_ALL) && strpos($server_software, 'LiteSpeed') === false;
	}

	public static function get_block_by_name($blocks, $search) {
		$queue = $blocks;

		while (!empty($queue)) {
			$current_block = array_shift($queue);

			if ($search === $current_block['blockName']) {
				return $current_block;
			}

			if (isset($current_block['innerBlocks'])) {
				// Add nested blocks to the end of the queue for processing
				$queue = array_merge($queue, $current_block['innerBlocks']);
			}
		}

		return false;
	}

	/**
	 * Only checks if user can install/activate plugins
	 *
	 * @param [type] $cap
	 * @param [type] ...$args
	 * @return void
	 */
	public static function current_user_can($cap, ...$args) {
		$user = wp_get_current_user();

		// Multisite super admin has all caps by definition, Unless specifically denied.
		if (is_multisite() && is_super_admin($user->ID)) {
			return true;
		}

		$caps = map_meta_cap($cap, $user->ID, ...$args);

		switch ($cap) {
			case 'install_plugins':
			case 'upload_plugins':
				$caps = ['install_plugins'];
				break;
			case 'install_themes':
			case 'upload_themes':
				$caps = ['install_themes'];
				break;
			case 'activate_plugins':
			case 'deactivate_plugins':
			case 'activate_plugin':
			case 'deactivate_plugin':
				$caps = ['activate_plugins'];
				break;
			default:
				break;
		}

		// Maintain BC for the argument passed to the "user_has_cap" filter.
		$args = array_merge(array($cap, $user->ID), $args);

		/**
		 * See WP_User::has_cap() for description.
		 */
		$capabilities = apply_filters('user_has_cap', $user->allcaps, $caps, $args, $user);

		// Everyone is allowed to exist.
		$capabilities['exist'] = true;

		// Nobody is allowed to do things they are not allowed to do.
		unset($capabilities['do_not_allow']);

		// Must have ALL requested caps.
		foreach ((array) $caps as $cap) {
			if (empty($capabilities[$cap])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Calculates the elapsed time and checks if it is close to the maximum execution time.
	 * Returns true if the script should exit to avoid exceeding the limit.
	 *
	 * @return bool True if the script should exit, false otherwise.
	 */
	public static function fsi_should_exit() {
		if (!defined('TEMPLATELY_START_TIME')) {
			return false;
		}

		$max_time = (int) ini_get('max_execution_time');
		$elapsed  = microtime(true) - TEMPLATELY_START_TIME;

		if ($max_time <= 0) {
			// The import request calls set_time_limit(0), which zeroes
			// max_execution_time — but gateways (FPM, LiteSpeed, nginx proxies)
			// still kill the request on THEIR clock, typically at 60s. With no
			// PHP limit to lean on, budget against a wall-clock ceiling instead
			// so runners keep chunking gracefully (and persisting their
			// backup_attributes) instead of dying mid-loop to a 504.
			$budget = (int) apply_filters('templately_fsi_request_time_budget', 25);

			if ($budget > 0 && $elapsed >= $budget) {
				return ['max_time' => $budget, 'elapsed' => $elapsed, 'delay' => 0];
			}

			return false;
		}

		$delay = max(5, $max_time * 20 / 100);

		// Check if elapsed time is close to max execution time
		if ($max_time - $elapsed <= $delay) {
			return ['max_time' => $max_time, 'elapsed' => $elapsed, 'delay' => $delay];
		}

		return false;
	}

	/**
	 * Enable Elementor Container
	 * This function will enable the Elementor Container feature.
	 * Without this feature, some of the templates may not work properly.
	 *
	 * @return boolean
	 */
	public static function enable_elementor_container() {
		if (class_exists('Elementor\Plugin')) {
			$control_name = Plugin::instance()->experiments->get_feature_option_key('container');
			if (get_option($control_name) !== 'active') {
				update_option($control_name, 'active');
				return true;
			}
		}
		return false;
	}

	/**
	 * Undocumented function
	 *
	 * @param [type] $args
	 * @param [type] $defaults
	 * @return array
	 */
	/**
	 * Returns true when the current WordPress site is running on a private/loopback
	 * host where an external API server cannot push callbacks (localhost, *.local,
	 * *.test, RFC-1918 addresses). Used as a fallback when the remote API does not
	 * return an explicit is_local_site flag.
	 */
	public static function is_local_site(): bool {
		$host = (string) parse_url( get_option( 'siteurl' ), PHP_URL_HOST );
		// Strip port if present (e.g. "localhost:8888")
		$host = (string) preg_replace( '/:\d+$/', '', $host );

		if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) ) {
			return true;
		}
		// Common local/dev TLDs that no public DNS resolves (an external API server
		// cannot push callbacks to them): .local/.test (mDNS/RFC-6761), .tst (the
		// WPDeveloper sandbox), .localhost, .dev.
		// `substr()` rather than `str_ends_with()`: the plugin advertises WordPress 5.0 /
		// PHP 7.2, `str_ends_with()` is PHP 8.0+, and core only polyfills it from WP 5.9 —
		// so the call fatals on a host inside our own advertised floor.
		foreach ( [ '.local', '.test', '.tst', '.localhost' ] as $suffix ) {
			if ( substr( $host, - strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		// RFC-1918 private ranges
		if ( preg_match( '/^192\.168\./', $host )
			|| preg_match( '/^10\./', $host )
			|| preg_match( '/^172\.(1[6-9]|2[0-9]|3[01])\./', $host ) ) {
			return true;
		}
		return false;
	}

	public static function recursive_wp_parse_args($args, $defaults) {
		$args     = (array) $args;
		$defaults = (array) $defaults;
		$r = $defaults;
		foreach ($args as $key => $value) {
			if (is_array($value) && isset($r[$key])) {
				// also handle numeric array. if both $value and $r[ $key ] are numeric array. wp_is_numeric_array()
				if (wp_is_numeric_array($value) && wp_is_numeric_array($r[$key])) {
					foreach ($value as $k => $v) {
						if (!in_array($v, $r[$key])) {
							if (!isset($r[$key][$k])) {
								$r[$key][$k] = $v;
							} else {
								$r[$key][] = $v;
							}
						}
					}
				} else {
					$r[$key] = self::recursive_wp_parse_args($value, $r[$key]);
				}
			} else {
				$r[$key] = $value;
			}
		}
		return $r;
	}

	/**
	 * Creates the plugin's working directory under wp-uploads and blocks direct
	 * web access to it.
	 *
	 * Everything the importer needs on disk lands here: the extracted pack (its
	 * WXR, its template JSON, its attachments), the AI-generated page JSON, and
	 * the FSI logs. wp-uploads is web-served, so these paths are not private just
	 * because their session id is a uuid — the guards are what makes them
	 * unreadable, not the name.
	 *
	 * .htaccess covers Apache and is inherited by everything below this point;
	 * web.config covers IIS; index.php stops a directory listing on any server.
	 * nginx honours none of them, so an nginx site still needs a location rule —
	 * this raises the floor, it does not replace server configuration.
	 *
	 * @param string $dir Absolute path to create and protect.
	 *
	 * @return bool Whether the directory exists and is usable.
	 */
	public static function protect_directory( $dir ) {
		if ( empty( $dir ) ) {
			return false;
		}

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$guards = [
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# Templately working files — not for direct access.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
		];

		foreach ( $guards as $file => $contents ) {
			$path = trailingslashit( $dir ) . $file;
			// Never overwrite: a site owner may have relaxed these deliberately.
			if ( ! file_exists( $path ) ) {
				@file_put_contents( $path, $contents ); // phpcs:ignore
			}
		}

		return true;
	}

	/**
	 * Absolute path to the plugin's protected working directory in wp-uploads.
	 *
	 * @param string $sub Optional subdirectory ('tmp', 'log', 'preview', ...).
	 *
	 * @return string Trailing-slashed path, or '' when uploads is unusable.
	 */
	public static function upload_dir( $sub = '' ) {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		$base = trailingslashit( $upload_dir['basedir'] ) . 'templately' . DIRECTORY_SEPARATOR;

		// The guards go on the root so every subdirectory inherits them.
		self::protect_directory( $base );

		return '' === $sub ? $base : trailingslashit( $base . $sub );
	}

}
