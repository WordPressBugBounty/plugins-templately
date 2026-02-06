<?php

namespace Templately\Utils;

use Elementor\Plugin;
use Templately\Core\Importer\Utils\Utils;
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
		// Only check TEMPLATELY_DEV_API constant - no fallback mechanisms
		return defined( 'TEMPLATELY_DEV_API' ) && constant( 'TEMPLATELY_DEV_API' );
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
	 * @return string
	 */
	public static function get_ip() {
		$ip = '127.0.0.1'; // Local IP
		if (! empty($_SERVER['HTTP_CLIENT_IP'])) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			$ip = ! empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : $ip;
		}

		return sanitize_text_field($ip);
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
		];

		// Add Content-Type for POST requests
		if (strtoupper($method) === 'POST') {
			$headers['Content-Type'] = 'application/json';
		}

		// Merge additional headers
		$headers = array_merge($headers, $extra_headers);

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

		return $response;
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

		// Add query parameters if provided
		if (!empty($query_params)) {
			$api_url = add_query_arg($query_params, $api_url);
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

		// Check if header exists and has a truthy value
		if (!empty($verification_header) && filter_var($verification_header, FILTER_VALIDATE_BOOLEAN)) {
			try {
				// Get current user data
				$options = Options::get_instance();
				$user = $options->get('user');

				// Only update if user data exists and is not already verified
				if (!empty($user) && is_array($user) && empty($user['is_verified'])) {
					// Set verification flag
					$user['is_verified'] = true;

					// Save updated user data
					$options->set('user', $user);

				}

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
		$additional_data['status'] = $status;
		if (! empty($endpoint)) {
			$additional_data['endpoint'] = $endpoint;
		}
		// Add browser padding to avoid browsers not serving small JSON responses
		$padding_length = 512;
		$additional_data['browser_padding'] = str_repeat(' ', $padding_length);

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
		trigger_error("Call to undefined method $class::$method() in $file on line $line", E_USER_ERROR);
	}

	/**
	 * Printing Error Logs in debug.log file.
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

		// Only log if WP_DEBUG_LOG is enabled
		if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
			return;
		}

		// Format the log message
		$formatted_message = self::format_log_message($log, $context, $level);

		// Write to error log
		error_log($formatted_message);
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
		if (isset($_REQUEST['is_lightspeed']) && $_REQUEST['is_lightspeed'] === 'true') {
			return false;
		}
		return (!defined('TEMPLATELY_IGNORE_FLUSH_ALL') || !TEMPLATELY_IGNORE_FLUSH_ALL) && strpos($_SERVER['SERVER_SOFTWARE'], 'LiteSpeed') === false;
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
		if (defined('TEMPLATELY_START_TIME') && ini_get('max_execution_time')) {
			$max_time = ini_get('max_execution_time');
			$elapsed  = microtime(true) - TEMPLATELY_START_TIME;
			$delay    = max(5, $max_time * 20 / 100);

			// Check if elapsed time is close to max execution time
			if ($max_time - $elapsed <= $delay) {
				return ['max_time' => $max_time, 'elapsed' => $elapsed, 'delay' => $delay];
			}
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

}
