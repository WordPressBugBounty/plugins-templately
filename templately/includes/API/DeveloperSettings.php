<?php

namespace Templately\API;

use Templately\Core\Developer;
use WP_REST_Request;

/**
 * Developer Settings API
 *
 * Handles developer-only settings that are available when TEMPLATELY_DEVELOPER_MODE is enabled
 *
 * @since 3.3.4
 */
class DeveloperSettings extends API {

	/**
	 * @param $request WP_REST_Request
	 * @return bool
	 */
	public function permission_check( WP_REST_Request $request ) {
		// Only allow access if developer mode is enabled and user has manage_options capability
		return Developer::is_developer_mode_enabled() && current_user_can( 'manage_options' );
	}

	/**
	 * Register API routes
	 */
	public function register_routes() {
		$this->get( 'developer-settings', [ $this, 'get_settings' ] );
		$this->post( 'developer-settings', [ $this, 'update_settings' ] );
	}

	/**
	 * Get current developer settings
	 *
	 * @return array
	 */
	public function get_settings() {
		$settings = Developer::get_settings();
		$config = Developer::get_available_constants();
		$override_status = Developer::get_constant_override_status();

		return $this->success( [
			'success' => true,
			'data' => $settings,
			'config' => $config,
			'override_status' => $override_status,
		] );
	}

	/**
	 * Update developer settings
	 *
	 * Note: This method saves settings to the database but they won't take effect
	 * until the constants are updated in wp-config.php or the master constant is used.
	 *
	 * @return array
	 */
	public function update_settings() {
		$settings = $this->get_param( 'settings', [] );

		if ( empty( $settings ) ) {
			return $this->error( 'invalid_settings', __( 'No settings provided.', 'templately' ), 'update_settings', 400 );
		}

		// Use Developer class to update settings
		$success = Developer::update_settings( $settings );

		if ( ! $success ) {
			return $this->error( 'update_failed', __( 'Failed to update developer settings.', 'templately' ), 'update_settings', 500 );
		}

		// Get the sanitized settings
		$sanitized_settings = Developer::sanitize_settings( $settings );

		return $this->success( [
			'success' => true,
			'data'    => [
				'message'        => __( 'Developer settings saved successfully.', 'templately' ),
				'settings'       => $sanitized_settings,
				'note'           => __( 'Settings have been saved to the database. To activate them, add the generated constants to your wp-config.php file or ensure TEMPLATELY_DEVELOPER_MODE is enabled.', 'templately' )
			],
		] );
	}

	/**
	 * Get all available developer constants and their descriptions
	 *
	 * @return array
	 */
	public function get_available_constants() {
		$constants = Developer::get_available_constants();
		return $this->success( $constants );
	}

	/**
	 * Check if network admin functionality is available
	 *
	 * @return array
	 */
	public function get_network_admin_status() {
		$status = Developer::get_network_admin_status();
		return $this->success( $status );
	}
}
