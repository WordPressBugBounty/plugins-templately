<?php
namespace Templately\Core\Developer;

use Templately\Utils\Base;
use Templately\Utils\Helper;

/**
 * Developer API Manager
 *
 * Handles API endpoint selection and legacy constant compatibility for developer features.
 * Follows the proper module architecture pattern used by other developer modules.
 *
 * @since 3.3.4
 */
class ApiManager extends Base {

	/**
	 * Initialize ApiManager module
	 *
	 * Handles legacy TEMPLATELY_DEV constant compatibility and sets up hooks
	 */
	public function __construct() {
		// Handle legacy TEMPLATELY_DEV constant compatibility
		$this->handle_legacy_constants();

		// Initialize hooks
		$this->init_hooks();
	}

	/**
	 * Handle legacy TEMPLATELY_DEV constant compatibility
	 *
	 * Automatically define TEMPLATELY_DEV_API as true if TEMPLATELY_DEV_API is not
	 * already defined AND TEMPLATELY_DEV constant is defined and true.
	 * This ensures existing setups using the old TEMPLATELY_DEV constant continue to work.
	 */
	private function handle_legacy_constants() {
		if ( ! defined( 'TEMPLATELY_DEV_API' ) && defined( 'TEMPLATELY_DEV' ) && constant( 'TEMPLATELY_DEV' ) ) {
			define( 'TEMPLATELY_DEV_API', true );
		}
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		add_filter( 'templately_admin_localized_data', [ $this, 'filter_localized_data' ] );
	}

	/**
	 * Check if development API should be used
	 *
	 * Simplified logic: if TEMPLATELY_DEV_API is defined and true, use dev server.
	 * Otherwise, use production server.
	 *
	 * @return bool True if development API should be used
	 */
	public static function is_dev_api() {
		return defined( 'TEMPLATELY_DEV_API' ) && constant( 'TEMPLATELY_DEV_API' );
	}

	/**
	 * Filter localized data to add API connection information
	 *
	 * @param array $data The localized data array
	 * @return array Modified localized data
	 */
	public function filter_localized_data( $data ) {
		$data['dev_api'] = Helper::is_dev_api();

		return $data;
	}
}
