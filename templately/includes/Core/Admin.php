<?php
namespace Templately\Core;

use Exception;
use PriyoMukul\WPNotice\Notices;
use PriyoMukul\WPNotice\Utils\CacheBank;
use PriyoMukul\WPNotice\Utils\NoticeRemover;
use Templately\Modules\Auth\REST\Login;
use Templately\Modules\FullSiteImport\FullSiteImport;
use Templately\Admin\Roles;
use Templately\Core\Capabilities;
use Templately\Utils\Base;
use Templately\Utils\Helper;
use Templately\Utils\Options;
use Templately\Modules\ElementorIntegration\Platform\Elementor;
use Templately\Modules\GutenbergIntegration\Platform\Gutenberg;

class Admin extends Base {

	/**
	 * @var CacheBank
	 */
	private static $cache_bank;

	/**
	 * Initially invoked function.
	 * Menu, Assets and maybe redirect on plugin activation is initialized.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', [ $this, 'scripts' ] );
		add_action( 'load-toplevel_page_templately', [ $this, 'before_scripts' ] );
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );

		self::$cache_bank = CacheBank::get_instance();

		try {
			add_action( 'admin_init', [ $this, 'notices' ], 11 );
		} catch ( Exception $e ) {
			unset( $e );
		}

		add_filter( 'plugin_action_links_' . TEMPLATELY_PLUGIN_BASENAME, [$this, 'handleActionLinks'], 10, 2 );

		// Post-import feedback mount point moved to
		// modules/post-import-feedback/Widget.php::render_mount_point() (spec 027) —
		// hooked independently on admin_footer.

		// Remove OLD notice from 1.0.0 (if other WPDeveloper plugin has notice)
		NoticeRemover::get_instance( '1.0.0' );
	}

	public function register_post_type() {
		$labels = [
			'name'                  => _x( 'Theme Builders', 'Post type general name', 'templately' ),
			'singular_name'         => _x( 'Theme Builder', 'Post type singular name', 'templately' ),
			'menu_name'             => _x( 'Theme Builder', 'Admin Menu text', 'templately' ),
			'name_admin_bar'        => _x( 'Book', 'Add New on Toolbar', 'templately' ),
			'add_new'               => __( 'Add New', 'templately' ),
			'add_new_item'          => __( 'Add New Template', 'templately' ),
			'new_item'              => __( 'New Template', 'templately' ),
			'edit_item'             => __( 'Edit Template', 'templately' ),
			'view_item'             => __( 'View Template', 'templately' ),
			'all_items'             => __( 'All Templates', 'templately' ),
			'search_items'          => __( 'Search Templates', 'templately' ),
			'parent_item_colon'     => __( 'Parent Templates:', 'templately' ),
			'not_found'             => __( 'No templates found.', 'templately' ),
			'not_found_in_trash'    => __( 'No templates found in Trash.', 'templately' ),
			'featured_image'        => _x( 'Template Cover Image', 'Overrides the “Featured Image” phrase for this post type. Added in 4.3', 'templately' ),
			'set_featured_image'    => _x( 'Set cover image', 'Overrides the “Set featured image” phrase for this post type. Added in 4.3', 'templately' ),
			'remove_featured_image' => _x( 'Remove cover image', 'Overrides the “Remove featured image” phrase for this post type. Added in 4.3', 'templately' ),
			'use_featured_image'    => _x( 'Use as cover image', 'Overrides the “Use as featured image” phrase for this post type. Added in 4.3', 'templately' ),
			'archives'              => _x( 'Template archives', 'The post type archive label used in nav menus. Default “Post Archives”. Added in 4.4', 'templately' ),
			'insert_into_item'      => _x( /** @lang text */ "Insert Into Theme Builder", 'Overrides the “Insert into post”/”Insert into page” phrase (used when inserting media into a post). Added in 4.4', 'templately' ),
			'uploaded_to_this_item' => _x( 'Uploaded to this template', 'Overrides the “Uploaded to this post”/”Uploaded to this page” phrase (used when viewing media attached to a post). Added in 4.4', 'templately' ),
			'filter_items_list'     => _x( 'Filter templates list', 'Screen reader text for the filter links heading on the post type listing screen. Default “Filter posts list”/”Filter pages list”. Added in 4.4', 'templately' ),
			'items_list_navigation' => _x( 'Templates list navigation', 'Screen reader text for the pagination heading on the post type listing screen. Default “Posts list navigation”/”Pages list navigation”. Added in 4.4', 'templately' ),
			'items_list'            => _x( 'Templates list', 'Screen reader text for the items list heading on the post type listing screen. Default “Posts list”/”Pages list”. Added in 4.4', 'templately' ),
		];

		$args = [
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'query_var'          => true,
			'rewrite'            => [ 'slug' => 'templately-library' ],
			// Kept in sync with the theme-builder module's own registration of this
			// same post type (modules/theme-builder/Source.php) — whichever runs last
			// wins, so both must carry the administrator-only capability map.
			'capability_type'    => 'post',
			'capabilities'       => Roles::builder_post_type_capabilities(),
			'map_meta_cap'       => true,
			'has_archive'        => true,
			'hierarchical'       => false,
			'menu_position'      => 10,
			'supports'           => [ 'title', 'editor', 'author', 'thumbnail', 'custom-field' ],
		];

		register_post_type( 'templately_library', $args );
	}

	public function before_scripts() {
		header("x-frame-options: ALLOW-FROM *.templately.com");
	}

	/**
	 * Enqueuing Assets
	 *
	 * @param  string $hook
	 * @return void
	 */
	public function scripts( $hook = '' ) {
		$allowed_hooks = apply_filters(
			'templately_admin_enqueue_allowed_hooks',
			[ 'index.php', 'edit.php', 'toplevel_page_templately', 'elementor', 'gutenberg', 'templately_page_templately_settings' ],
			$hook
		);

		if ( !empty($hook) && ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

		$templately_settings = [];
		// if('templately_page_templately_settings' === $hook){
			$kit = null;
			$el_settings = [];
			if(defined('ELEMENTOR_VERSION')){
				$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
				if($kit){
					$el_settings = $kit->get_settings();
				}
			}
			$site_logo_id = get_theme_mod( 'custom_logo' );
			$eb_settings  = get_option('eb_global_styles', []);
			$eb_settings  = is_array($eb_settings) ? $eb_settings: [];
			$eb_settings  = array_map(function($item) { return json_decode($item, true); }, $eb_settings);
			$site_logo    = [
				'id'   => $site_logo_id,
				'url'  => $site_logo_id ? wp_get_attachment_url($site_logo_id) : '',
				// 'size' => (int) get_option('templately_site_logo_size', ''),
			];

			$templately_settings = [
				'nonce'             => wp_create_nonce( 'templately_nonce' ),
				'has_revert'        => FullSiteImport::has_revert(),
				'has_elementor'     => $this->check_plugin_status( 'elementor/elementor.php' ),
				'has_eb'            => $this->check_plugin_status( 'essential-blocks/essential-blocks.php' ),
				'site_logo'         => $site_logo,
				'elementor'         => $el_settings,
				'gutenberg'         => $eb_settings,
				'siteTitle'         => get_option('blogname', ''),
				'siteTagline'       => get_option('blogdescription', ''),
				'customCSS'         => get_option('templately_custom_css', ''),
				// 'imported_platform' => get_option('templately_import_platform', ''),
			];
		// }

		if('index.php' === $hook){
			// need separate condition so we can return if page is index.php
			// Post-import feedback widget enqueue/localize moved to
			// modules/post-import-feedback/Widget.php (spec 027) — hooked independently
			// on admin_enqueue_scripts.
			return;
		}

		$templately = [];

		if ( 'edit.php' === $hook ) {
			global $current_screen;
			if ( $current_screen->post_type !== 'templately_library' ) {
				return;
			}

			$types = templately()->theme_builder::$templates_manager->get_template_types();
			$types = array_filter( $types, function ( $item ) {
				return $item::get_property( 'builder' ) || $item::get_property( 'builder' ) === null;
			} );

			$types = array_reduce( $types, function ( $carry, $item ) {
				return array_merge( $carry, [
					[
						'value' => call_user_func( [ $item, 'get_type' ] ),
						'label' => call_user_func( [ $item, 'get_title' ] )
					]
				] );
			}, [] );

			$templately = [
				'types' => $types
			];
		}

		$script_dependencies = ['regenerator-runtime'];
		$_localize_handle    = 'templately';
		$_current_screen     = 'templately';

		// The webpack "commons" chunk (splitChunks) holds the react-src core + vendor code SHARED
		// across the main bundle AND every module bundle — extracted once instead of duplicated
		// into each. It MUST load before any of them. Making the main 'templately' handle depend
		// on it means every module bundle (which depends on 'templately') gets it transitively, in
		// the right order. Its own .asset.php lists no deps (DEWP doesn't attribute externals to
		// shared chunks), so declare the WordPress script globals its shared code references here.
		if ( file_exists( TEMPLATELY_PATH . 'assets/js/templately-commons.js' ) ) {
			templately()->assets->register( 'templately-commons', 'js/templately-commons.js', [
				'react', 'react-dom', 'react-jsx-runtime', 'wp-element', 'wp-hooks', 'wp-i18n',
				'wp-api-fetch', 'wp-url', 'wp-data', 'wp-components', 'lodash',
			], true );
			$script_dependencies[] = 'templately-commons';
		}

		if ( $hook === 'elementor' || $hook === 'gutenberg' ) {
			$_current_screen       = $hook;
			$_localize_handle      = 'templately-' . $hook;
			$script_dependencies[] = $_localize_handle;
		}

		if ( 'templately_page_templately_settings' === $hook ) {
			templately()->assets->register( 'settings-vendor', 'js/chunks/settings-vendor.js', [], true );
			$script_dependencies[] = 'settings-vendor';
		}

		if ( $hook === 'toplevel_page_templately' || $hook == 'edit.php' || 'templately_page_templately_settings' === $hook ) {
			templately()->assets->enqueue( 'templately-admin', 'css/admin.css', [ 'templately' ] );
		}

		// Google Fonts Enqueueing
		// DM Sans - Used in general UI
		templately()->assets->enqueue(
			'templately-dmsans',
			set_url_scheme( '//fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400;1,500;1,700&display=swap' )
		);

		// Inter - Used in Tailwind components and various UI elements
		templately()->assets->enqueue(
			'templately-inter',
			set_url_scheme( '//fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap' )
		);

		// DM Mono - Used in settings and code-related UI
		templately()->assets->enqueue(
			'templately-dmmono',
			set_url_scheme( '//fonts.googleapis.com/css2?family=DM+Mono:ital,wght@0,300;0,400;0,500;1,300;1,400;1,500&display=swap' )
		);

		wp_enqueue_style('wp-components');

		wp_enqueue_media();
		templately()->assets->enqueue( 'templately', 'js/templately.js', $script_dependencies, true );
		// splitChunks moves the shared component CSS into the commons chunk's stylesheet
		// (templately-commons.css) and leaves the per-entry templately.css empty/absent. Register
		// the shared CSS under the SAME 'templately' style handle so every stylesheet that depends
		// on it (templately-tailwind, templately-admin) still resolves — WP won't enqueue a style
		// whose dependency handle is unregistered. Fall back to templately.css if a build didn't
		// split (no commons chunk).
		$main_css = file_exists( TEMPLATELY_PATH . 'assets/css/templately-commons.css' ) ? 'css/templately-commons.css' : 'css/templately.css';
		templately()->assets->enqueue( 'templately', $main_css, ['templately-dmsans', 'templately-inter'] );

		$tailwind_hooks = apply_filters( 'templately_tailwind_hooks', [ 'toplevel_page_templately', 'elementor', 'gutenberg', 'templately_page_templately_settings', 'edit.php' ] );
		if ( in_array( $hook, $tailwind_hooks, true ) ) {
			templately()->assets->enqueue( 'templately-tailwind', 'css/tailwind.css', ['templately', 'templately-inter'] );
		}

		// MODULE STYLES. @wordpress/scripts splits extracted CSS by ORIGIN, not by feature:
		// `{entry}.css` holds CSS reached from a stylesheet entry point, while
		// `style-{entry}.css` holds CSS `import`ed from JS. Every module's SCSS is imported from
		// its own index (e.g. modules/auth/assets/js/index.tsx imports ../scss/style.scss), so
		// with the modules folded into the `templately` graph ALL of it now lands in
		// style-templately.css — a file nothing enqueued.
		//
		// This is the regression that shipped the sign-in screen completely unstyled: the header
		// and banner came from templately.css and looked fine, so the page rendered but the
		// module-owned views did not. Before the merge these styles were emitted per module to
		// assets/css/modules/*.css and picked up by enqueue_module_assets()'s glob; that glob is
		// gone, and this is its replacement.
		//
		// ORDER MATTERS, and this must stay AFTER the Tailwind enqueue. The retired glob ran at
		// the very end of this method — after Tailwind — so module rules beat Tailwind utilities
		// on equal specificity. Enqueueing it earlier silently inverts that: the AI sidebar chat
		// bubble lost its overflow-wrap to a Tailwind utility and long URLs broke out of the
		// bubble (caught by 020-ai-chat-bubble-overflow.spec.js, not by eye).
		if ( file_exists( TEMPLATELY_PATH . 'assets/css/style-templately.css' ) ) {
			templately()->assets->enqueue( 'templately-modules', 'css/style-templately.css', [ 'templately' ] );
		}

		// Module assets are no longer enqueued separately: every feature module compiles into
		// the 'templately' bundle via react-src/generated/moduleManifest.ts (Constitution XIII).
		// The former enqueue_module_assets() globbed assets/js/modules/*.js and loaded all of
		// them unconditionally wherever this ran, so folding them into the main handle is a
		// parity change, not a scope change.

		/**
		 * @var Elementor|Gutenberg $platform
		 */
		$platform = $this->platform( $_current_screen );

		$global_user  = Options::get_instance()->get( 'user', false, get_current_user_id() );
		$hide_buttons = get_option('templately-gutenberg-hide-buttons', 'no');
		$hide_buttons = $hide_buttons === 'yes' ? 'yes' : 'no';

		$templately = array_merge( [
			'url'                => home_url(),
			'site_url'           => site_url(),
			'nonce'              => wp_create_nonce( 'templately_nonce' ),
			'is_ssl'             => is_ssl(),
			'rest_args'          => [
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'endpoint' => get_rest_url( null, 'templately/v1/' )
			],
			"icons"                   => [
				'construction' => templately()->assets->icon( 'icons/construction.gif' ),
				'profile'      => templately()->assets->icon( 'icons/profile.svg' ),
				'warning'      => templately()->assets->icon( 'icons/warning.png' )
			],
			'locale'                  => get_locale(),
			'promo_image'             => templately()->assets->icon( 'single-page-promo.png' ),
			'default_image'           => templately()->assets->icon( 'clouds/cloud-item.svg' ),
			'not_found'               => templately()->assets->icon( 'no-item-found.png' ),
			'no_items'                => templately()->assets->icon( 'no-items.png' ),
			'loadingImage'            => templately()->assets->icon( 'logos/loading-logo.gif' ),
			'current_url'             => admin_url( 'admin.php?page=templately' ),
			'wp_dashboard_url'        => admin_url( 'index.php' ),
			'templately_library_url'  => admin_url( 'edit.php?post_type=templately_library' ),
			'is_signed'               => Login::is_signed(),
			'is_globally_signed'      => Login::is_globally_signed(),
			'signed_as_global'        => Login::signed_as_global(),
			'current_screen'          => $_current_screen,
			'can_fsi'                 => current_user_can('install_plugins') && current_user_can('install_themes'),
			'post_type'               => get_post_type(),
			'has_elementor'           => rest_sanitize_boolean( is_plugin_active( 'elementor/elementor.php' ) ),
			'has_elementor_pro'       => rest_sanitize_boolean( is_plugin_active( 'elementor-pro/elementor-pro.php' ) ),
			'theme'                   => $_current_screen == 'templately' ? 'light' : $platform->ui_theme(),
			'is_wp_support_gutenberg' => version_compare( get_bloginfo( 'version' ), '5.0.0', '>=' ),
			'hide_buttons'            => $hide_buttons,
			'settings'                => $templately_settings,
			'wp_user_avatar'          => get_avatar_url( get_current_user_id(), [ 'size' => 96 ] ),
			'allowed_mime_types'      => get_allowed_mime_types(),
			'is_free_user'            => $global_user === false || isset( $global_user['plan'] ) && $global_user['plan'] == 'free',
			'is_disconnected'         => ! empty( $global_user['is_disconnected'] ),
			'tour_status'             => Options::get_instance()->get( 'tour_status', [] ),
			// Host-capability map (spec 053) — the ONE delivery point for every
			// Templately bundle (admin SPA and editor surfaces all localize here).
			// Always sourced from the registry, never hand-built (FR-025).
			'caps'                    => Capabilities::get_instance()->get_map(),
			// Mirrors the account-scoped server gate so the client can hide what it
			// would only be 403'd for: Subscription (a plan purchase is made FOR the
			// account, and Checkout gates `subscription` on `manage_options`).
			// Hidden rather than shown-and-refused — offering a button that cannot
			// work is worse than not offering it.
			'can_manage_account'      => current_user_can( 'manage_options' ),
			// Which Templately site the plugin talks to. `templatelyDeveloper.dev_api`
			// carries the same flag, but only when developer mode is on and only from
			// its own script handle — so anything rendered before that handle runs (the
			// footer CTA, for one) linked to the live site while the plugin was pointed
			// at dev. Kept here, on the main localized object, `webURL()` is right from
			// the first paint. See `react-src/utils/helper.ts#webURL`.
			'dev_api'                 => Helper::is_dev_api(),
		], $templately );

		// Apply filter to allow network admin modifications
		$templately = apply_filters( 'templately_admin_localized_data', $templately );

		templately()->assets->localize( $_localize_handle, 'templately', $templately );

		do_action( 'templately_admin_scripts', $hook );
	}

	/**
	 * Check the status of a plugin.
	 *
	 * @param string $plugin_path Plugin path relative to the plugins directory.
	 * @return mixed true if active, false if not installed, 0 if installed but not active.
	 */
	private function check_plugin_status( $plugin_path ) {
		$plugins = get_plugins();
		if ( isset( $plugins[ $plugin_path ] ) ) {
			return is_plugin_active( $plugin_path ) ? true : 0;
		}
		return false;
	}

	/**
	 * Admin notices for Review and others.
	 *
	 * @since 2.0.0
	 * @return void
	 * @throws Exception
	 * @since 2.0.0
	 */
	public function notices() {
		$notices = new Notices( [
			// 'dev_mode'       => true, // also need to clear templately_notices from option/site meta table.
			'id'             => 'templately',
			'storage_key'    => 'notices',
			'lifetime'       => 3,
			'stylesheet_url' => TEMPLATELY_ASSETS . 'css/notices.css',
			'styles'         => TEMPLATELY_ASSETS . 'css/notices.css',
			'priority'       => 2,
		] );

		$global_user     = Options::get_instance()->get( 'user', false, get_current_user_id() );
		$download_counts = Options::get_instance()->get( 'total_download_counts', 0, get_current_user_id() );
		$cloud_items     = 0;
		if ( isset( $global_user['my_cloud']['usages'] ) ) {
			$cloud_items = intval( $global_user['my_cloud']['usages'] );
		}

		if ( $cloud_items >= 5 || $download_counts >= 4 ) {
			$message = sprintf( __( "We hope you're enjoying %s! Could you please do us a favor and give us a review on %s to help us spread the word and boost our motivation?", 'templately' ), '<strong>Templately</strong>', '<strong>WordPress</strong>' );

			$_review_notice = [
				'thumbnail' => templately()->assets->icon( 'logos/logo.svg' ),
				'html'      => '<p>' . $message . '</p>',
				'links'     => [
					'later'            => [
						'link'       => 'https://wordpress.org/support/plugin/templately/reviews/#new-post',
						'target'     => '_blank',
						'label'      => __( 'Sure, you deserve it!', 'templately' ),
						'icon_class' => 'dashicons dashicons-external'
					],
					'allready'         => [
						'label'      => __( 'I already did', 'templately' ),
						'icon_class' => 'dashicons dashicons-smiley',
						'attributes' => [
							'data-dismiss' => true
						]
					],
					'maybe_later'      => [
						'label'      => __( 'Maybe Later', 'templately' ),
						'icon_class' => 'dashicons dashicons-calendar-alt',
						'attributes' => [
							'data-later' => true,
							'class'      => 'dismiss-btn'
						]
					],
					'support'          => [
						'link'       => Helper::web_url( '', [ 'support' => 'open' ] ),
						'attributes' => [
							'target' => '_blank'
						],
						'label'      => __( 'I need help', 'templately' ),
						'icon_class' => 'dashicons dashicons-sos'
					],
					'never_show_again' => [
						'label'      => __( 'Never show again', 'templately' ),
						'icon_class' => 'dashicons dashicons-dismiss',
						'attributes' => [
							'data-dismiss' => true
						]
					]
				]
			];

			$notices->add( 'review', $_review_notice, [
				'start'       => $notices->strtotime(),
				'recurrence'  => 20,
				'dismissible' => true,
				'refresh'     => TEMPLATELY_VERSION,
				'screens'     => [
					'dashboard',
					'plugins',
					'themes',
					'edit-page',
					'edit-post',
					'users',
					'tools',
					'options-general',
					'nav-menus'
				]
			] );
		}

		if ( $global_user === false || isset( $global_user['plan'] ) && $global_user['plan'] == 'free' ) {
			$crown = '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m15.743 10.938.161-1.578c.086-.842.142-1.399.098-1.749h.015c.727 0 1.316-.622 1.316-1.389s-.589-1.389-1.315-1.389c-.727 0-1.316.622-1.316 1.39 0 .346.12.663.32.907-.287.186-.66.58-1.223 1.171-.434.456-.65.684-.893.72a.7.7 0 0 1-.394-.059c-.223-.104-.372-.385-.67-.95l-1.57-2.97a22 22 0 0 0-.476-.873c.569-.306.958-.93.958-1.65C10.754 1.496 9.97.667 9 .667s-1.754.829-1.754 1.852c0 .72.389 1.344.958 1.65-.139.234-.293.525-.476.873l-1.57 2.97c-.298.565-.447.846-.67.95a.7.7 0 0 1-.394.058c-.242-.035-.46-.263-.893-.719-.563-.592-.937-.985-1.223-1.171.2-.244.32-.56.32-.908 0-.767-.589-1.389-1.316-1.389-.726 0-1.315.622-1.315 1.39 0 .766.589 1.388 1.315 1.388h.016c-.045.35.012.906.098 1.749l.16 1.578c.09.876.164 1.71.255 2.46H15.49c.09-.75.165-1.584.254-2.46m-7.698 6.395h1.908c2.488 0 3.732 0 4.562-.784.362-.342.591-.959.757-1.762H2.727c.166.803.395 1.42.757 1.762.83.784 2.074.784 4.562.784" fill="#fff"/></svg>';

			// $notices->add( 'upsale', wp_sprintf( '<p>%1$s <a target="_blank" href="%3$s">%2$s</a>.</p>', __( '🔥 Get access to 6,500+ Ready Templates & save up to 65% OFF now', 'templately' ), __( 'Upgrade to Pro', 'templately' ), 'https://templately.com/#pricing' ), [
			// 	'start'       => $notices->strtotime( '+10 day' ),
			// 	'dismissible' => true,
			// 	'refresh'     => TEMPLATELY_VERSION,
			// 	'screens'     => [
			// 		'dashboard',
			// 		'plugins',
			// 		'themes',
			// 		'edit-page',
			// 		'edit-post',
			// 		'users',
			// 		'tools',
			// 		'options-general',
			// 		'nav-menus'
			// 	]
			// ] );

			// spring_savings_2026 notice
			$_notice_text_spring_savings_2026 = '<p style="margin-top: 0; margin-bottom: 0px;">🌸 Spring Savings: Design Website Quickly With 6,500+ Elementor & Gutenberg Templates, Including AI-Powered Features ⚡️ – Now <strong>Flat 50% OFF!</strong></p>';

			$_spring_savings_2026 = [
				'thumbnail' => templately()->assets->icon( 'logos/logo-full.svg' ),
				'html'      => $_notice_text_spring_savings_2026,
				'links'     => [
					'later'     => [
						'link'       => Helper::web_url() . '#pricing',
						'target'     => '_blank',
						'label'      => __( 'Upgrade To Pro Now', 'templately' ),
						'attributes' => [
							'class' => 'button button-primary',
						]
					],
					'never_show_again' => [
						'label'      => __( 'Maybe Later', 'templately' ),
						'attributes' => [
							'data-dismiss' => true,
							'class'        => 'button button-link dismiss-btn',
						]
					],
				],
			];

			$notices->add( 'spring_savings_2026', $_spring_savings_2026, [
				'start'       => $notices->strtotime('00:00:01AM 8th April, 2026'),
				'recurrence'  => false,
				'dismissible' => true,
				'refresh'     => TEMPLATELY_VERSION,
				"expire"      => strtotime( '11:59:59pm 10th May, 2026' ),
				'display_if'  => !wp_is_mobile(),
				'screens'     => [
					'dashboard',
				],
			] );

			$holiday_text = sprintf(
				'<p style="margin-top: 0; margin-bottom: 0px;"> 🎁 %s <strong>%s</strong> %s</p>',
				__('Get', 'templately'),
				__('Templately PRO', 'templately'),
				__('with up to 60% OFF & unlock 6500+ ready WordPress templates to power up web design in 2025.', 'templately')
			);

			$holiday_text .= "<div class='wpnotice-button-wrapper'>";
			$holiday_text .= sprintf(
				'<a class="button button-primary" target="_blank" href="%2$s">%1$s</a>',
				$crown . __('GET PRO Lifetime Access', 'templately'),
				Helper::web_url() . '#pricing'
			);
			$holiday_text .= sprintf(
				'<button class="button button-link dismiss-btn" data-dismiss="true">%1$s</button>',
				__('No, I\'ll Pay Full Price Later', 'templately')
			);
			$holiday_text .= "</div>";


			$_holiday = [
				'thumbnail' => templately()->assets->icon( 'logos/logo-full.svg' ),
				'html'      => $holiday_text,
			];

			// $notices->add( 'holiday', $_holiday, [
			// 	'start'       => $notices->strtotime( '+0 minute' ),
			// 	'recurrence'  => false,
			// 	'dismissible' => true,
			// 	'refresh'     => TEMPLATELY_VERSION,
			// 	"expire"      => strtotime( '11:59:59pm 10th January, 2025' ),
			// 	'display_if'  => !wp_is_mobile(),
			// 	'screens'     => [
			// 		'dashboard',
			// 	],
			// ] );
		}

		self::$cache_bank->create_account( $notices );
		self::$cache_bank->calculate_deposits( $notices );
	}

	/**
	 * Adding Menu In Sidebar ( WordPress Left-side Dashboard Menu )
	 *
	 * @return void
	 */
	public function admin_menu() {
		// TODO: Role Management

		// 58.7 is the TOP-LEVEL slot: a float is correct and always has been
		// there, and the fractional part is what keeps Templately from
		// colliding with another plugin sitting at the same integer position.
		add_menu_page( 'Templately', 'Templately', 'delete_posts', 'templately', '', templately()->assets->icon( 'logos/logo-icon.svg' ), 58.7 );

		// The submenu calls pass NO position on purpose. `58.7` was copied down
		// from the top-level slot, where it never meant anything — a submenu
		// $position is an index within THIS menu, and 58.7 is past the end of a
		// four-item list, so every WordPress we support already just appended.
		// It was not merely useless though: before WP 6.0, $position had to be
		// an int (`is_int()`, not `is_numeric()` — a float fails it too), so
		// each call emitted `_doing_it_wrong` on every admin load of a
		// WP_DEBUG site inside the advertised floor (readme: WP 5.0) and then
		// discarded the value anyway. Omitting it appends — verified identical
		// ordering on WP 5.9 and 7.0 — and is silent on every version.
		add_submenu_page( 'templately', 'Templately', 'Template Library', 'delete_posts', 'templately', [
			$this,
			'display'
		] );

		add_submenu_page( 'templately', 'Theme Builder', 'Theme Builder', Roles::CAP_BUILDER, 'edit.php?post_type=templately_library', '' );

		// add_submenu_page( 'templately', 'Settings', 'Settings', 'administrator', 'templately-settings', [
		// 	Settings::get_instance(),
		// 	'display'
		// ], '58.7' );
	}

	public function display() {
		Helper::views( 'template-library', [
			'show_grid_skeleton' => self::is_template_library_path(),
		] );
	}

	/**
	 * Whether the current `?path=` targets the template-library grid
	 * (`TemplatesTypeLayout`, e.g. `elementor/packs`) — the only route the
	 * pre-mount skeleton's card grid actually matches. Every other route
	 * (`clouds/*`, `downloads`, `favourites`, `manage-sites`, `subscription`,
	 * `purchased-items`, `ai-sites`, `settings/*`, item-detail pages, and
	 * Saved Templates) gets a generic shell instead, so a hard reload doesn't
	 * flash the wrong page shape before React mounts. See
	 * views/template-library.php.
	 *
	 * Public + static (it was private on the pre-modular branch) purely so the
	 * path parsing can be unit-tested on its own: it reads nothing but `$_GET`
	 * and touches no instance state, so there is nothing for an Admin instance
	 * to contribute, and constructing one would register every admin hook.
	 *
	 * @return bool
	 */
	public static function is_template_library_path() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing hint for a skeleton; no state is changed.
		$path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';

		if ( $path === '' ) {
			return true;
		}

		$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );

		$non_library_prefixes = [ 'clouds', 'downloads', 'favourites', 'manage-sites', 'subscription', 'purchased-items', 'ai-sites', 'settings' ];

		if ( in_array( $segments[0] ?? '', $non_library_prefixes, true ) ) {
			return false;
		}

		// `/:platform/:type` (the grid) vs `/:platform/:type/:slug` (item
		// detail, its own skeleton shape) or `/:platform/templates` (Saved
		// Templates, a table) — only the 2-segment form matches the grid.
		return count( $segments ) === 2 && $segments[1] !== 'templates';
	}

	/**
	 * If Elementor doesn't exists.
	 *
	 * @return void
	 */
	public static function has_no_elementor() {
		$plugin_url  = \wp_nonce_url( \self_admin_url( 'update.php?action=install-plugin&amp;plugin=elementor' ), 'install-plugin_elementor' );
		$button_text = __( 'Install Elementor', 'templately' );
		if ( isset( Helper::get_plugins()['elementor/elementor.php'] ) ) {
			$plugin_url  = \wp_nonce_url( 'plugins.php?action=activate&amp;plugin=elementor/elementor.php', 'activate-plugin_elementor/elementor.php' );
			$button_text = __( 'Activate Elementor', 'templately' );
		}
		$output = '<div class="notice notice-error">';
		$output .= sprintf(
			"<p><strong>%s</strong> %s <strong>%s</strong> %s &nbsp;&nbsp;<a  class='button-primary' href='%s'>%s</a></p>",
			__( 'Templately', 'templately' ),
			__( 'requires', 'templately' ),
			__( 'Elementor', 'templately' ),
			__( 'plugin to be installed and activated. Please install Elementor to continue.', 'templately' ),
			esc_url( $plugin_url ),
			$button_text
		);
		$output .= '</div>';
		echo wp_kses_post( $output );
	}

	public function header() {
		Helper::views( 'header' );
	}

	/**
	 * Handle links displayed below the plugin name in the WordPress Installed Plugins page.
	 *
	 * @return  array
	 * @since   3.1.2
	 * @static
	 *
	 */
	public static function handleActionLinks($links, $file)
	{
		$settingsLink = '<a href="' . admin_url('admin.php?page=templately') . '" aria-label="' . __('Open settings page',
				'templately') . '">' . __('Templates Library', 'templately') . '</a>';

		$links[] = $settingsLink;
		return $links;
	}
}
