<?php
/**
 * Templately plugin.
 *
 * The main plugin handler class is responsible for initializing Templately. The
 * class registers and all the components required to run the plugin.
 *
 * @package Templately
 */

namespace Templately;

use Templately\Admin\API\Settings as APISettings;
use Templately\Admin\Settings;
use Templately\API\AIContent;
use Templately\API\LogoGeneration;
use Templately\API\Conditions;
use Templately\API\ThemeBuilderApi;
use Templately\Builder\ThemeBuilder;
use Templately\Core\Importer\FullSiteImport;
use Templately\Utils\AuthErrorCode;
use Templately\Utils\Base;
use Templately\Utils\Database;
use Templately\Utils\Enqueue;

use Templately\Core\Admin;
use Templately\Core\Module;

use Templately\API\Tags;
use Templately\API\Items;
use Templately\API\Login;
use Templately\API\Checkout;
use Templately\API\SignUp;
use Templately\API\AiCredit;
use Templately\API\Profile;
use Templately\API\Import;
use Templately\API\MyClouds;
use Templately\API\WorkSpaces;
use Templately\API\Categories;
use Templately\API\Dependencies;
use Templately\API\TemplateTypes;
use Templately\API\SavedTemplates;
use Templately\API\Sites;
use Templately\API\Tour;
use Templately\Core\DeactivationSurvey;
use Templately\Core\Maintenance;
use Templately\Core\Migrator;
use Templately\Core\Platform\Gutenberg;
use Templately\Core\Platform\Elementor;

final class Plugin extends Base {
    public $version = '3.7.5';

	public $admin;
	public $settings;
	/**
	 * Enqueue class responsible for assets
	 * @var Enqueue
	 */
	public $assets;

	/**
	 * @var ThemeBuilder
	 */
	public $theme_builder;

	/**
	 * @var Developer
	 */
	public $developer;

	/**
	 * Plugin constructor.
	 * Initializing Templately plugin.
	 *
	 * @access private
	 */
	public function __construct() {
		$this->define_constants();
		$this->set_locale();

		Maintenance::init();
		DeactivationSurvey::init();

		$this->assets        = Enqueue::get_instance( TEMPLATELY_URL, TEMPLATELY_PATH, $this->version );
		$this->admin         = Admin::get_instance();
		$this->settings      = Settings::get_instance();
		$this->theme_builder = ThemeBuilder::get_instance();

		// Initialize developer functionality if available
		$this->init_developer_functionality();

		add_action( 'plugins_loaded', [ $this, 'plugins_loaded' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		add_action( 'init', [ $this, 'google_login_handler' ] );

		/**
		 * Initialize.
		 */
		do_action( 'templately_init' );

	}

	/**
	 * Cloning is forbidden.
	 *
	 * @since 2.0
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, __( 'Cloning is forbidden.', 'templately' ), '2.0' );
	}

	/**
	 * Un-serializing instances of this class is forbidden.
	 *
	 * @since 2.0
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, __( 'Un-serializing instances of this class is forbidden.', 'templately' ), '2.0' );
	}

	/**
	 * Initializing Things on Plugins Loaded
	 * @return void
	 */
	public function plugins_loaded() {
		$this->platforms(); // PLATFORMS LOADED
		$this->apis(); // APIs LOADED

		/**
		 * Migrator for Templately
		 */
		Migrator::get_instance();

		/**
		 * Full Site Import
		 */
		FullSiteImport::get_instance();
	}

	/**
	 * Initialize developer functionality if available
	 *
	 * This method safely loads developer functionality only if the developer
	 * directory and class exist, preventing fatal errors in production builds.
	 *
	 * @return void
	 */
	private function init_developer_functionality() {
		$developer_file = TEMPLATELY_PATH . 'includes/Core/Developer/Developer.php';

		// Check if developer file exists before attempting to load
		if ( file_exists( $developer_file ) ) {
			// Include the developer class file
			require_once $developer_file;

			// Check if the class exists after including the file
			if ( class_exists( '\\Templately\\Core\\Developer\\Developer' ) ) {
				$this->developer = \Templately\Core\Developer\Developer::get_instance();
			}
		}

		// If developer functionality is not available, set to null
		if ( ! isset( $this->developer ) ) {
			$this->developer = null;
		}
	}



	/**
	 * Initialize all platforms
	 * @return void
	 */
	public function platforms() {
		Gutenberg::get_instance();
		Elementor::get_instance();
	}

	/**
	 * All the API instantiated
	 *
	 * @return void
	 */
	private function apis() {
		Conditions::get_instance();
		Categories::get_instance();
		TemplateTypes::get_instance();
		Dependencies::get_instance();
		Tags::get_instance();
		ThemeBuilderApi::get_instance();

		AIContent::get_instance();
		LogoGeneration::get_instance();
		Items::get_instance();
		SavedTemplates::get_instance();

		Login::get_instance();
		Checkout::get_instance();
		SignUp::get_instance();
		Import::get_instance();
		Profile::get_instance();
		AiCredit::get_instance();
		MyClouds::get_instance();
		WorkSpaces::get_instance();
		Sites::get_instance();
		Tour::get_instance();

		APISettings::get_instance();
		// Note: DeveloperSettings::get_instance() is called in Developer::init_modules() when developer functionality is available and enabled
	}

	/**
	 * Register all REST API endpoints
	 * @return void
	 */
	public function register_routes() {
		if ( ! empty( $modules = Module::get_instance()->get( 'API' ) ) ) {
			foreach ( $modules as $module ) {
				$module->object->register_routes();
			}
		}
	}

	/**
	 * Define CONSTANTS
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public function define_constants() {
		$this->define( 'TEMPLATELY_URL', plugin_dir_url( TEMPLATELY_FILE ) );
		$this->define( 'TEMPLATELY_ASSETS', TEMPLATELY_URL . 'assets/' );
		$this->define( 'TEMPLATELY_PLUGIN_BASENAME', plugin_basename( TEMPLATELY_FILE ) );
		$this->define( 'TEMPLATELY_VERSION', $this->version );
		$this->define( 'TEMPLATELY_API_NAMESPACE', 'templately/v1' );
		$this->define( 'TEMPLATELY_VIEWS_ABSPATH', TEMPLATELY_PATH . 'views/' );
	}

	/**
	 * Define constant if not already set.
	 *
	 * @param string $name Constant name.
	 * @param mixed $value Constant value.
	 *
	 * @return void
	 */
	private function define( string $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	/**
	 * Setting the locale for translation availability
	 * @return void
	 * @since 1.0.0
	 */
	public function set_locale() {
		add_action( 'init', [ $this, 'load_textdomain' ] );
	}

	/**
	 * Loading Text Domain on init HOOK
	 * @return void
	 * @since 1.0.0
	 *
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'templately', false, dirname( TEMPLATELY_PLUGIN_BASENAME ) . '/languages' );
	}

	public function google_login_handler() {
		// Stop if not a templately google login request
		if ( empty( $_GET['templately_google_login'] ) ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Checked before the token is consumed: the callback can land while the
		// auth cookie is missing (expired session, cookie not yet set), and WP
		// will bounce the user through wp-login and back to this same URL.
		// Burning the token here would fail that legitimate retry.
		if ( ! is_user_logged_in() ) {
			return;
		}

		$state = '';
		if ( ! empty( $_GET['templately_state'] ) ) {
			$state = sanitize_text_field( wp_unslash( $_GET['templately_state'] ) );
		} elseif ( ! empty( $_GET['state'] ) ) {
			$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		}

		$state_user_id = false;
		if ( ! empty( $state ) ) {
			$state_user_id = Database::get_transient( 'google_state_' . $state );
			Database::delete_transient( 'google_state_' . $state );
		}

		$is_authorized = false !== $state_user_id
			&& intval( $state_user_id ) === get_current_user_id()
			&& current_user_can( 'delete_posts' );

		$redirect_url = remove_query_arg( [ 'templately_google_login', 'templately_state', 'api_key', 'error', 'state', 'redirect-to' ] );

		if ( ! $is_authorized ) {
			$error_code = AuthErrorCode::AUTH_STATE_INVALID;
		} elseif ( ! empty( $_GET['error'] ) ) {
			// Google's own reason is deliberately dropped rather than forwarded:
			// everything on this query string is attacker-controlled, and the
			// screen that displays it must never be handed prose from the URL.
			$error_code = AuthErrorCode::AUTH_PROVIDER_FAILED;
		} elseif ( ! empty( $_GET['api_key'] ) ) {
			$request = new \WP_REST_Request( 'POST', '/templately/v1/login' );
			$request->set_param( 'viaAPI', true );
			$request->set_param( 'api_key', sanitize_text_field( $_GET['api_key'] ) );

			/**
			 * @var Login $login
			 */
			$login = Login::get_instance();
			$login->permission_check( $request );

			// login() pins the write target to the acting user itself — no pin
			// here, or its finally would release ours mid-request.
			$response = $login->login();

			if ( ! is_wp_error( $response ) && ! empty( $response['user'] ) ) {
				$redirect_path = ! empty( $_GET['redirect-to'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect-to'] ) ) : '';
				if ( ! empty( $redirect_path ) ) {
					if ( filter_var( $redirect_path, FILTER_VALIDATE_URL ) ) {
						$redirect_url = $redirect_path;
					} else {
						$is_templately = strpos( $redirect_url, 'page=templately' ) !== false;
						$is_elementor  = strpos( $redirect_url, 'action=elementor' ) !== false;
						// Gutenberg editor usually has action=edit or is a block editor page
						$is_gutenberg  = ( strpos( $redirect_url, 'action=edit' ) !== false || strpos( $redirect_url, 'post_type=' ) !== false ) && ! $is_elementor;

						if ( $is_templately || $is_elementor || $is_gutenberg ) {
							$redirect_url = add_query_arg( 'path', ltrim( $redirect_path, '/' ), $redirect_url );

							// Always open the modal in editors after google login
							if ( $is_elementor || $is_gutenberg ) {
								$redirect_url = add_query_arg( 'templately_open_modal', '1', $redirect_url );
							}
						}
					}
				}

				wp_safe_redirect( $redirect_url );
				exit;
			} else {
				// The cloud's own wording stays server-side; the screen resolves
				// its copy from the code.
				$error_code = AuthErrorCode::INVALID_API_KEY;
			}
		} else {
			$error_code = AuthErrorCode::AUTH_MISSING_API_KEY;
		}

		$redirect_url = add_query_arg( [
			'templately_error' => $error_code,
		], $redirect_url );

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
