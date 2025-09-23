<?php
namespace Templately\Core;

use Templately\Core\Developer\NetworkAdmin;
use Templately\Core\Developer\ApiManager;
use Templately\Core\Developer\TestEndpoints;
use Templately\Utils\Base;

/**
 * Developer Feature Controller
 *
 * Centralized management of all developer-only features and functionality.
 * This class serves as the main controller for developer mode features.
 *
 * @since 3.3.4
 */
class Developer extends Base {

	/**
	 * @var ApiManager
	 */
	public $api_manager;

	/**
	 * @var NetworkAdmin
	 */
	public $network_admin;

	/**
	 * @var TestEndpoints
	 */
	public $test_endpoints;

	/**
	 * Cached constant override status captured before constants are defined
	 *
	 * @var array|null
	 */
	private static $cached_override_status = null;

	/**
	 * Available developer constants and their descriptions
	 *
	 * @var array
	 */
	private static $available_constants = [
		'TEMPLATELY_DEV' => [
			'label' => 'Development Mode',
			'description' => 'Enable general development mode features',
			'type' => 'boolean',
		],
		'TEMPLATELY_DEV_API' => [
			'label' => 'Development API',
			'description' => 'Use development API endpoints instead of production',
			'type' => 'boolean',
		],
		'TEMPLATELY_DEBUG_LOG' => [
			'label' => 'Debug Logging',
			'description' => 'Enable detailed debug logging for troubleshooting',
			'default' => true,
			'type' => 'boolean',
		],
		'TEMPLATELY_IGNORE_AI_ANIMATE' => [
			'label' => 'Ignore AI Animations',
			'description' => 'Skip AI animation effects for faster development',
			'default' => true,
			'type' => 'boolean',
		],
		'TEMPLATELY_DEV_AI_ALL_IMAGES' => [
			'label' => 'Show All AI Image Types',
			'description' => 'Show all image attachment types in AI conversations including backgrounds, icons, and other non-photo images. By default, only photo-type attachments are displayed.',
			'default' => true,
			'type' => 'boolean',
		],
		'IMPORT_DEBUG' => [
			'label' => 'Import Debug',
			'description' => 'Enable debugging for import operations',
			'type' => 'boolean',
		],
		'TEMPLATELY_HTTP_RETRY' => [
			'label' => 'HTTP Retry Count',
			'description' => 'Number of times to retry failed HTTP requests',
			'type' => 'number',
			'min' => 1,
			'max' => 10,
		],
		'WP_DEBUG' => [
			'label' => 'WordPress Debug',
			'description' => 'Enable WordPress debug mode',
			'default' => true,
			'type' => 'boolean',
		],
		'SCRIPT_DEBUG' => [
			'label' => 'Script Debug',
			'description' => 'Use unminified scripts for debugging',
			'default' => true,
			'type' => 'boolean',
		],
	];

	/**
	 * Initialize developer functionality
	 */
	public function __construct() {
		// Only initialize if developer mode is enabled
		if ( ! $this->is_developer_mode_enabled() ) {
			return;
		}

		// Cache override status before defining constants
		self::get_constant_override_status();

		$this->define_constants();

		$this->init_hooks();
		$this->init_modules();
	}

	/**
	 * Check if developer mode is enabled
	 *
	 * @return bool
	 */
	public static function is_developer_mode_enabled() {
		return defined( 'TEMPLATELY_DEVELOPER_MODE' ) && constant( 'TEMPLATELY_DEVELOPER_MODE' );
	}

	/**
	 * Initialize hooks for developer functionality
	 */
	private function init_hooks() {
		// Filter for developer mode localized data
		add_filter( 'templately_admin_localized_data', [ $this, 'filter_localized_data' ] );
	}

	/**
	 * Initialize developer modules
	 */
	private function init_modules() {
		// Initialize API manager for development API endpoint handling
		$this->api_manager = new ApiManager();

		// Initialize network admin functionality
		$this->network_admin = NetworkAdmin::get_instance();

		// Initialize test endpoints for Playwright testing
		$this->test_endpoints = new TestEndpoints();
	}

	/**
	 * Define developer constants
	 */
	private function define_constants() {
		$settings = self::get_settings();
		foreach ( self::$available_constants as $constant => $args ) {
			if ( defined( $constant ) ) {
				continue;
			}
			if ( !empty( $settings[ $constant ] ) ) {
				define( $constant, $settings[ $constant ] );
			} elseif ( !isset($settings[ $constant ]) && isset( $args['default'] ) ) {
				define( $constant, $args['default'] );
			}
		}
	}

	/**
	 * Filter localized data to add developer mode flag
	 *
	 * @param array $data The localized data array
	 * @return array Modified localized data
	 */
	public function filter_localized_data( $data ) {
		$data['developer_mode'] = $this->is_developer_mode_enabled();

		// Add developer-related constants to localized data
		$data['log'] = defined( 'TEMPLATELY_DEBUG_LOG' ) && constant( 'TEMPLATELY_DEBUG_LOG' );
		$data['dev_mode'] = defined( 'TEMPLATELY_DEV' ) && constant( 'TEMPLATELY_DEV' );
		$data['ai_animate_ignore_mode'] = defined( 'TEMPLATELY_IGNORE_AI_ANIMATE' ) && constant( 'TEMPLATELY_IGNORE_AI_ANIMATE' );
		$data['dev_ai_all_images'] = defined( 'TEMPLATELY_DEV_AI_ALL_IMAGES' ) && constant( 'TEMPLATELY_DEV_AI_ALL_IMAGES' );

		return $data;
	}

	/**
	 * Get current developer settings with priority order:
	 * 1. Defined constant value (highest priority)
	 * 2. Saved database value (fallback)
	 * 3. Default value from constant definition (final fallback)
	 *
	 * @return array
	 */
	public static function get_settings() {
		$final_settings = [];

		// Get saved settings from database
		$saved_settings = get_option( 'templately_developer_settings', [] );

		foreach ( self::$available_constants as $constant => $args ) {
			$default = $args['default'] ?? false;

			// Priority 1: Use defined constant value (highest priority)
			if ( defined( $constant ) ) {
				$final_settings[ $constant ] = constant( $constant );
			}
			// Priority 2: Use saved database value (fallback)
			elseif ( isset( $saved_settings[ $constant ] ) ) {
				$final_settings[ $constant ] = $saved_settings[ $constant ];
			}
			// Priority 3: Use default value (final fallback)
			else {
				$final_settings[ $constant ] = $default;
			}
		}

		return $final_settings;
	}

	/**
	 * Update developer settings in database
	 *
	 * @param array $settings The settings to save
	 * @return bool Success status
	 */
	public static function update_settings( $settings ) {
		// Validate and sanitize settings
		$sanitized_settings = self::sanitize_settings( $settings );

		if ( empty( $sanitized_settings ) ) {
			return false;
		}

		// Save to database
		update_option( 'templately_developer_settings', $sanitized_settings );

		return true;
	}

	/**
	 * Sanitize developer settings
	 *
	 * @param array $settings Raw settings
	 * @return array Sanitized settings
	 */
	public static function sanitize_settings( $settings ) {
		$sanitized = [];

		foreach ( self::$available_constants as $constant => $args ) {
			if ( isset( $settings[ $constant ] ) ) {
				if ( $constant === 'TEMPLATELY_HTTP_RETRY' ) {
					$sanitized[ $constant ] = max( $args['min'], min( $args['max'], intval( $settings[ $constant ] ) ) );
				} else {
					$sanitized[ $constant ] = rest_sanitize_boolean( $settings[ $constant ] );
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Get available developer constants
	 *
	 * @return array
	 */
	public static function get_available_constants() {
		return self::$available_constants;
	}

	/**
	 * Get constant override status for each developer constant
	 *
	 * Returns information about which constants are defined vs. controlled by database settings
	 *
	 * @return array Array with constant names as keys and override info as values
	 */
	public static function get_constant_override_status() {
		// Return cached result if available
		if ( self::$cached_override_status !== null ) {
			return self::$cached_override_status;
		}

		$override_status = [];

		foreach ( array_keys( self::$available_constants ) as $constant ) {
			$override_status[ $constant ] = [
				'is_defined' => defined( $constant ),
				'current_value' => defined( $constant ) ? constant( $constant ) : null,
				'is_overridden' => defined( $constant ), // Same as is_defined for clarity
			];
		}

		self::$cached_override_status = $override_status;
		return $override_status;
	}

	/**
	 * Check if a specific developer feature is enabled
	 *
	 * @param string $feature The feature constant name
	 * @return bool
	 */
	public static function is_feature_enabled( $feature ) {
		// First check if the constant is defined
		if ( defined( $feature ) ) {
			return constant( $feature );
		}

		// Fall back to saved settings
		$settings = get_option( 'templately_developer_settings', [] );
		return isset( $settings[ $feature ] ) ? $settings[ $feature ] : false;
	}

	/**
	 * Get network admin status information
	 *
	 * @return array
	 */
	public static function get_network_admin_status() {
		return [
			'available' => is_multisite() && self::is_developer_mode_enabled(),
			'multisite' => is_multisite(),
			'developer_mode' => self::is_developer_mode_enabled(),
			'network_admin' => is_network_admin(),
			'can_manage_network' => current_user_can( 'manage_network' ),
		];
	}

	/**
	 * Log debug message if debug logging is enabled
	 *
	 * @param string $message The message to log
	 * @param string $context Optional context for the log
	 */
	public static function debug_log( $message, $context = 'templately' ) {
		if ( self::is_feature_enabled( 'TEMPLATELY_DEBUG_LOG' ) ) {
			error_log( "[{$context}] {$message}" );
		}
	}

	/**
	 * Check if we should ignore AI animations
	 *
	 * @return bool
	 */
	public static function should_ignore_ai_animate() {
		return self::is_feature_enabled( 'TEMPLATELY_IGNORE_AI_ANIMATE' );
	}

	/**
	 * Get HTTP retry count
	 *
	 * @return int
	 */
	public static function get_http_retry_count() {
		$count = self::is_feature_enabled( 'TEMPLATELY_HTTP_RETRY' );
		return is_numeric( $count ) ? max( 1, min( 10, intval( $count ) ) ) : 3;
	}
}
