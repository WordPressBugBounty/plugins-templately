<?php
namespace Templately\Core\Developer;

use Templately\Core\Importer\FullSiteImport;
use Templately\Utils\Base;

/**
 * Network Admin functionality for WordPress Multisite
 *
 * This class handles all network admin specific functionality for Templately
 * when running in a WordPress multisite environment under developer mode.
 *
 * @since 3.3.4
 */
class NetworkAdmin extends Base {

	/**
	 * Initialize network admin functionality
	 *
	 * Only initializes if we're in developer mode and this is a multisite installation
	 */
	public function __construct() {
		// Only initialize if developer mode is enabled and we're in multisite
		if ( ! $this->should_initialize() ) {
			return;
		}

		$this->init_hooks();
	}

	/**
	 * Check if network admin functionality should be initialized
	 *
	 * @return bool
	 */
	private function should_initialize() {
		// Check if developer mode is enabled
		if ( ! defined( 'TEMPLATELY_DEVELOPER_MODE' ) || ! constant( 'TEMPLATELY_DEVELOPER_MODE' ) ) {
			return false;
		}

		// Check if this is a multisite installation
		if ( ! is_multisite() ) {
			return false;
		}

		return true;
	}

	/**
	 * Initialize hooks for network admin functionality
	 */
	private function init_hooks() {
		// Hook into admin menu for network admin
		add_action( 'network_admin_menu', [ $this, 'add_network_admin_menu' ] );

		// Hook for FSI import functionality
		add_action( 'templately_network_admin_fsi_import', [ $this, 'handle_fsi_import' ] );

		// Hook for multisite subsite creation during FSI
		add_action( 'templately_fsi_before_import', [ $this, 'handle_multisite_creation' ], 10, 2 );

		// Filter for network admin localized data
		add_filter( 'templately_admin_localized_data', [ $this, 'filter_localized_data' ] );

		// Filter for API request modifications
		add_filter( 'templately_api_request_params', [ $this, 'filter_api_request_params' ], 10, 3 );
	}

	/**
	 * Add network admin menu
	 *
	 * This replaces the inline network admin menu code from Admin.php
	 */
	public function add_network_admin_menu() {
		// Add main menu page for network admin
		add_menu_page(
			'Templately',
			'Templately',
			'manage_network',
			'templately',
			[ $this, 'display_network_admin_page' ],
			templately()->assets->icon( 'logos/logo-icon.svg' ),
			'58.7'
		);

		// Add submenu page for template library
		add_submenu_page(
			'templately',
			'Templately',
			'Template Library',
			'manage_network',
			'templately',
			[ $this, 'display_network_admin_page' ],
			'58.7'
		);

		// add settings page to network admin
		add_submenu_page(
			'templately',
			'Settings',
			'Settings',
			'manage_network',
			'templately_settings',
			[ $this, 'display_settings_page' ]
		);

		// Note: Theme Builder is not added for network admin as per original logic
	}

	/**
	 * Display network admin page
	 */
	public function display_network_admin_page() {
		// Use the same display method as regular admin
		\Templately\Utils\Helper::views( 'template-library' );
	}

	/**
	 * Display settings page
	 */
	public function display_settings_page() {
		\Templately\Utils\Helper::views( 'settings' );
	}

	/**
	 * Handle FSI import for network admin
	 *
	 * This method can be called via the action hook to trigger FSI import
	 * functionality from network admin context.
	 */
	public function handle_fsi_import() {
		// Ensure we have proper permissions
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'templately' ) );
		}

		// Trigger the FSI import process
		// This would be called by the React component or AJAX handler
		do_action( 'templately_fsi_import_triggered' );
	}

	/**
	 * Handle multisite subsite creation during FSI import
	 *
	 * @param object $fsi_instance The FullSiteImport instance
	 * @param array $request_params The request parameters
	 */
	public function handle_multisite_creation( $fsi_instance, $request_params ) {
		// Only handle if we're in network admin and multisite
		if ( ! is_multisite() || empty( $_GET['isNetworkAdmin'] ) ) {
			return;
		}

		$progress = $request_params['progress'] ?? [];

		if ( empty( $progress['create_multi_site'] ) ) {
			// Create new multisite subsite with proper network type detection
			$title   = $request_params['title'];
			$tagline = $request_params['slogan'];
			$user_id = get_current_user_id();

			$new_site = $this->create_multisite_subsite( $user_id, $progress, $title, $tagline, $fsi_instance );

			// Update request params after site creation
			$fsi_instance->request_params = $fsi_instance->get_session_data();
		} else {
			$blog_id = $request_params['blog_id'];
			// Switch to the existing site
			switch_to_blog( $blog_id );
		}

		$fsi_instance->request_params = $fsi_instance->get_session_data();
		$fsi_instance->initialize_props();
	}

	/**
	 * Filter localized data for network admin
	 *
	 * @param array $data The localized data array
	 * @return array Modified localized data
	 */
	public function filter_localized_data( $data ) {
		// Only modify if we're in network admin
		if ( ! is_network_admin() ) {
			return $data;
		}

		// Set the isNetworkAdmin flag to ensure it's properly set
		$data['isNetworkAdmin'] = true;

		// Add any network-specific configuration
		$data['networkAdminEnabled'] = true;

		return $data;
	}

	/**
	 * Filter API request arguments for network admin
	 *
	 * This allows network admin functionality to modify request arguments
	 * when needed, including handling multisite-specific API configurations.
	 *
	 * @param array $args The API request arguments
	 * @param string $method The HTTP method (GET/POST)
	 * @param string $endpoint The API endpoint
	 * @return array Modified arguments
	 */
	public function filter_api_request_params( $args, $method, $endpoint ) {
		// Only modify if we're in multisite network admin context
		if ( ! is_multisite() || ! isset( $_GET['isNetworkAdmin'] ) || ! current_user_can( 'manage_network' ) ) {
			return $args;
		}

		// Handle multisite network admin API requests
		$main_site_id = get_main_site_id();
		$network_home_url = network_home_url();

		// Switch to main site to get the correct API key
		switch_to_blog( $main_site_id );
		$api_key = \Templately\Utils\Options::get_instance()->get( 'api_key' );
		restore_current_blog();

		// Update headers with network admin specific values
		if ( isset( $args['headers'] ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $api_key;
			$args['headers']['x-templately-url'] = $network_home_url;
		}

		return $args;
	}

	/**
	 * Get network admin specific settings
	 *
	 * @return array Network admin settings
	 */
	public function get_network_settings() {
		return [
			'enabled' => $this->should_initialize(),
			'multisite_type' => $this->get_multisite_type(),
			'main_site_id' => get_main_site_id(),
			'network_home_url' => network_home_url(),
		];
	}

	/**
	 * Detect multisite installation type
	 *
	 * @return string 'subdomain' or 'subdirectory'
	 */
	private function get_multisite_type() {
		if ( defined( 'SUBDOMAIN_INSTALL' ) && SUBDOMAIN_INSTALL ) {
			return 'subdomain';
		}

		if ( function_exists( 'is_subdomain_install' ) && is_subdomain_install() ) {
			return 'subdomain';
		}

		return 'subdirectory';
	}

	/**
	 * Create a new multisite subsite for FSI import
	 *
	 * @param int $user_id The user ID
	 * @param array $progress The progress array
	 * @param string $title The site title
	 * @param string $tagline The site tagline/description
	 * @param object $fsi_instance The FullSiteImport instance
	 * @return int The new site ID
	 * @throws Exception
	 */
	private function create_multisite_subsite( $user_id, $progress, $title, $tagline, $fsi_instance ) {
		// Get the current network site
		$current_site = get_current_site();
		$domain = $current_site->domain;

		// Detect network type (subdomain vs subdirectory)
		$is_subdomain_network = is_subdomain_install();

		// Generate site identifier from title
		$site_slug = sanitize_title( $title );

		if ( $is_subdomain_network ) {
			// For subdomain networks: create subdomain like sitename.maindomain.com
			$subdomain = $site_slug . '.' . $domain;
			$path = '/';

			// Check if subdomain already exists
			$exists = domain_exists( $subdomain, $path, $current_site->id );
			if ( $exists ) {
				// Generate a unique subdomain if it already exists
				$i = 1;
				do {
					$new_subdomain = $site_slug . '-' . $i . '.' . $domain;
					$new_title = $title . ' ' . $i;
					$exists = domain_exists( $new_subdomain, $path, $current_site->id );
					$i++;
				} while ( $exists );
				$subdomain = $new_subdomain;
				$title = $new_title;
			}

			$site_domain = $subdomain;
			$site_path = $path;
		} else {
			// For subdirectory networks: create path like maindomain.com/sitename
			$path = '/' . $site_slug . '/';

			// Check if path already exists
			$exists = domain_exists( $domain, $path, $current_site->id );
			if ( $exists ) {
				// Generate a unique path if it already exists
				$i = 1;
				do {
					$new_path = '/' . $site_slug . '-' . $i . '/';
					$new_title = $title . ' ' . $i;
					$exists = domain_exists( $domain, $new_path, $current_site->id );
					$i++;
				} while ( $exists );
				$path = $new_path;
				$title = $new_title;
			}

			$site_domain = $domain;
			$site_path = $path;
		}

		// Prepare site data
		$new_site_data = array(
			'domain' => $site_domain,
			'path' => $site_path,
			'title' => $title,
			'user_id' => $user_id,
			'network_id' => $current_site->id,
		);

		// Create the new site
		$new_site = wp_insert_site( $new_site_data );

		if ( is_wp_error( $new_site ) ) {
			$fsi_instance->throw( __( 'Error creating site: ' . $new_site->get_error_message(), 'templately' ) );
		}

		// Initialize the new site
		wp_initialize_site( $new_site, array() );

		// Update session data with the new site ID (before switching context)
		$fsi_instance->update_session_data( [
			'blog_id' => $new_site,
			'network_url' => home_url( '/' ),
		] );

		// Mark multisite creation as complete
		$progress['create_multi_site'] = true;
		$fsi_instance->update_session_data( [ 'progress' => $progress ] );
		$fsi_instance->request_params = $fsi_instance->get_session_data();

		// Switch to the new site
		switch_to_blog( $new_site );

		$fsi_instance->update_session_data( $fsi_instance->request_params );

		// Set the tagline for the new site (we're already switched to the new site context)
		if ( ! empty( $tagline ) ) {
			// Since we're already switched to the new blog, use update_option directly
			// $result = update_option('blogdescription', $tagline);
			// if (!$result) {
			// 	// Log warning but don't fail the entire process
			// 	error_log('Templately: Failed to update blog description for site ' . $new_site);
			// }
		}

		// Log the successful creation
		$fsi_instance->sse_message( [
			'type' => 'eventLog',
			'action' => 'eventLog',
			'info' => 'create_log_dir',
			'results' => __METHOD__ . '::' . __LINE__,
		] );

		return $new_site;
	}
}
