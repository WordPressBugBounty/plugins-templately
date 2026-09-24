<?php

namespace Templately\Modules\ThemeBuilder;

use Templately\Admin\Roles;

use Elementor\Core\Base\Document;
use Templately\Modules\ThemeBuilder\Managers\LocationManager;
use Templately\Modules\ThemeBuilder\Managers\TemplateManager;
use Templately\Modules\ThemeBuilder\Managers\ThemeCompatibility;
use Templately\Modules\ThemeBuilder\Types\ThemeTemplate;
use Templately\Utils\Response\AjaxResponder;
use Templately\Utils\Response\ErrorCode;
use Templately\Utils\Base;
use Templately\Utils\Views;

class ThemeBuilder extends Base {
	/**
	 * Names of the theme-builder widgets shown in the editor panel.
	 *
	 * The widgets declare show_in_panel() => false, so they are hidden on every
	 * document by default; elementor_document_config() re-enables them (via the
	 * per-document panel.widgets_settings override) only for the
	 * templately_library CPT. Keep in sync with register_elements().
	 */
	const PANEL_WIDGETS = [
		'tl-post-title',
		'tl-post-content',
		'tl-post-fatured-image',
		'tl-site-logo',
		'tl-post-comments',
	];

	/**
	 * Post types that are `public` but must never be offered as display-condition
	 * targets.
	 *
	 * `templately_library` is registered `public => true, show_in_nav_menus => false`
	 * (see Source::register_post_type()), so it only stayed out of the condition
	 * dropdowns by ACCIDENT of the old `show_in_nav_menus` gate. Widening that gate
	 * without this list would let a template target the template library itself.
	 * `elementor_library`, `e-floating-buttons` and `e-landing-page` are Elementor's
	 * own builder-document CPTs (all `public => true`), and `attachment` has no
	 * meaningful theme-builder use. None of these are pages a visitor browses, so
	 * none belongs in a display condition.
	 */
	const EXCLUDED_POST_TYPES = [
		Source::CPT,
		'elementor_library',
		'e-floating-buttons',
		'e-landing-page',
		'attachment',
	];

	/**
	 * @var Source
	 */
	public static $source;

	/**
	 * @var \Templately\Modules\DisplayConditions\Managers\ConditionManager|null
	 */
	public static $conditions_manager;

	/**
	 * @var TemplateManager
	 */
	public static $templates_manager;

	/**
	 * @var LocationManager
	 */
	public static $location_manager;

	/**
	 * @var ThemeCompatibility
	 */
	public static $theme_compatibility;

	/**
	 * @var PageTemplates
	 */
	public static $page_template_module;

	public static $views;

	public function __construct() {
		self::$views = Views::get_instance( TEMPLATELY_VIEWS_ABSPATH );

		add_action( 'pre_get_posts', [ $this, 'pre_get_posts' ], 1 );
		add_action( 'wp', function () {
			new TemplateLoader( $this, self::$views );
		} );
		// Unconditional (admin + front end): the editor's admin page bootstrap
		// (post.php?action=elementor) never fires `wp`, so this can't be gated
		// behind the TemplateLoader instantiation above. See TemplateLoader::
		// register_preview_product_hooks() for why two render pathways are covered.
		TemplateLoader::register_preview_product_hooks();


		add_action( 'init', [ $this, 'source_register' ] );
		add_action( 'wp_ajax_templately_create_template', [ $this, 'create_template' ] );

		add_filter( 'elementor/document/config', [$this, 'elementor_document_config'], 10, 2 );
		add_filter( 'elementor/documents/get/post_id', [$this, 'elementor_documents_get_post_id'] );
		add_action( 'elementor/widgets/register', [ $this, 'register_elements' ] );

		add_filter( 'the_content', [$this, 'filter_content'], 10 ,1);

		self::$theme_compatibility  = new ThemeCompatibility();
		self::$location_manager     = new LocationManager( $this );
		self::$page_template_module = new PageTemplates();

	}



	public function pre_get_posts( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || empty( $query->query['post_type'] ) || $query->query['post_type'] !== 'templately_library' ) {
			return;
		}

		$template_types = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';

		if ( ! empty( $template_types ) ) {
			$query->set( 'meta_query', [
				[
					'key'   => Source::TYPE_META_KEY,
					'value' => $template_types,
				]
			] );
		}
	}

	public function source_register() {
		self::$templates_manager  = new TemplateManager( $this );
		// The ConditionManager is owned by the display-conditions module (spec 034,
		// extracted FROM theme-builder). It is supplied here through the
		// `templately_condition_manager` filter that display-conditions registers at
		// boot — inverting the old direct dependency so theme-builder no longer imports
		// the display-conditions namespace. Fires on `init`, long after
		// `plugins_loaded` has booted (and let the filter register) every module.
		self::$conditions_manager = apply_filters( 'templately_condition_manager', null, $this );
		self::$source             = new Source( $this );
		register_rest_field('templately_library','templately_type', [
			'get_callback'     => [ $this, 'get_rest_template_type' ],
		]);
	}

	public function get_rest_template_type() {
		return get_post_meta( get_the_ID(), '_templately_template_type', true );
	}

	public function create_template() {
		check_admin_referer( 'templately_create_template' );

		// A theme-builder template controls what every visitor sees in the header,
		// footer, archive and single slots. `delete_posts` is held by the Author
		// role, which is scoped to its own post content — gate on the plugin's own
		// administrator-only capability instead.
		if ( ! Roles::can_manage_builder() ) {
			AjaxResponder::error(ErrorCode::FORBIDDEN);
			return;
		}

		// Both fields are required; reading them unguarded raises an undefined-index
		// warning on any malformed POST instead of failing cleanly.
		if ( empty( $_POST['template_type'] ) || ! isset( $_POST['title'] ) ) {
			wp_die( esc_html__( 'Missing required template fields.', 'templately' ) );
		}

		$_type = sanitize_text_field( wp_unslash( $_POST['template_type'] ) );

		$_meta = [];

		if ( isset( $_POST['meta'] ) && is_array( $_POST['meta'] ) ) {
			$_meta = array_map( 'sanitize_text_field', wp_unslash( $_POST['meta'] ) );
		}

		$_post_data = [
			'post_type'  => 'templately_library',
			'post_title' => sanitize_text_field( wp_unslash( $_POST['title'] ) )
		];

		$template = self::$templates_manager->create( $_type, $_post_data, $_meta );

		if ( is_wp_error( $template ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_die() renders a WP_Error itself (title/status/message); stringifying loses that.
			wp_die( $template );
		}

		// wp_safe_redirect keeps the destination on an allowed host; the URL comes
		// from the templates manager, but there is no reason to accept an offsite one.
		wp_safe_redirect( $template->get_edit_url() );
		die;
	}

	public function get_public_post_types(): array {
		// `public` (not `show_in_nav_menus`) is the right gate: whether a post type
		// belongs in a nav menu says nothing about whether it has templatable views.
		// A CPT registered `show_in_nav_menus => false` — a very common choice —
		// still has singles and archives the theme builder must be able to target,
		// and the old filter made every one of them invisible here.
		$post_type_args = [
			'public' => true,
		];

		$_post_types = get_post_types( $post_type_args, 'objects' );

		/**
		 * Post types never offered as theme-builder display-condition targets.
		 *
		 * Other builder plugins register `public` CPTs that hold DOCUMENTS rather
		 * than visitor-facing pages; without this they would appear as meaningless
		 * condition entries the moment the gate above widened.
		 *
		 * @param array $excluded Post type slugs to exclude.
		 */
		$excluded = apply_filters( 'templately_builder_excluded_post_types', self::EXCLUDED_POST_TYPES );

		$post_types = [];

		foreach ( $_post_types as $post_type => $object ) {
			if ( in_array( $post_type, (array) $excluded, true ) ) {
				continue;
			}

			$post_types[ $post_type ] = $object->label;
		}

		return $post_types;
	}

	public function get_template( $template_id ) {
		$template = self::$templates_manager->get( $template_id );

		if ( ! empty( $template ) && ! $template instanceof ThemeTemplate ) {
			$template = null;
		}

		return $template;
	}

	/**
	 * Save additional data for a specific post.
	 *
	 * This function filters the data by adding additional configuration.
	 * External developers can use this function to add custom configuration for different posts.
	 * It checks if the post type is not 'templately_library', then it adds a new configuration
	 * 'theme-post-content' to the data.
	 *
	 * @param array $data The original data that needs to be saved.
	 * @param int   $post_id The ID of the post for which the data is being saved.
	 *
	 * @return array         The modified data with the additional configuration.
	 */
	public function elementor_document_config( $data, $post_id ) {
		if ( Source::CPT === get_post_type( $post_id ) ) {
			$data['panel'] = [
				'widgets_settings'    => array_merge(
					[
						'theme-post-content' => [
							'show_in_panel' => true,
						],
					],
					// Templately widgets are panel-hidden by default; surface
					// them only when editing a templately_library template.
					array_fill_keys( self::PANEL_WIDGETS, [ 'show_in_panel' => true ] )
				),
				'elements_categories' => [
					'theme-elements-archive' => [
						'title' => esc_html__( 'Archive', 'elementor-pro' ),
					],
					// The Templately widget group. This merge (array_replace_recursive
					// in Document::get_config) can only append new category keys, so it
					// lands last here — the editor-JS WidgetPanelModule reorders it to
					// the top of the panel on document:loaded.
					'templately'             => [
						'title'       => esc_html__( 'Templately', 'templately' ),
						'icon'        => 'eicon-folder',
						'hideIfEmpty' => false,
					],
				],
			];

			$conditions = self::$conditions_manager->get_conditions_for_display( $post_id );

			$data['templately_builder']['conditions'] = $conditions;
			$data['templately_builder']['post_type']  = get_post_type( $post_id );
		}

		return $data;
	}

	public function elementor_documents_get_post_id( $post_id ) {
		if ( Source::CPT === get_post_type( $post_id ) ) {
			add_filter( 'get_post_metadata', [ $this, 'modify_template_type' ], 10, 4 );
		}

		return $post_id;
	}

	public function modify_template_type( $value, $object_id, $meta_key, $single ) {
		$_value = $value;
		if ( empty($value) && Document::TYPE_META_KEY === $meta_key && Source::CPT === get_post_type( $object_id ) ) {
			remove_filter( 'get_post_metadata', [ $this, 'modify_template_type' ] );
			$value = get_post_meta( $object_id, Source::TYPE_META_KEY, $single );
			$arr = [
				'post'            => 'single-post',
				'page'            => 'single-page',
				'error'           => 'error-404',
				'product_single'  => 'product',
				'product_archive' => 'product-archive',
				'course_single'   => 'single-post',
				'course_archive'  => 'archive',
			];
			$key = $single ? $value : (isset($value[0]) ? $value[0] : '');
			$value = isset( $arr[ $key ] ) ? $arr[ $key ] : $value;
			if(in_array($value, ['header', 'footer'])){
				return $_value;
			}
		}

		return $value;
	}

	public function register_elements( $widgets_manager ) {
		$widgets_manager->register( new \Templately\Modules\ThemeBuilder\Widgets\Post_Title() );
		$widgets_manager->register( new \Templately\Modules\ThemeBuilder\Widgets\Post_Content() );
		$widgets_manager->register( new \Templately\Modules\ThemeBuilder\Widgets\Featured_Image() );
		$widgets_manager->register( new \Templately\Modules\ThemeBuilder\Widgets\Site_Logo() );
		$widgets_manager->register( new \Templately\Modules\ThemeBuilder\Widgets\Post_Comments() );
	}

	public function filter_content( $content ) {
		if(is_singular()){
			global $post;
			if(!empty($post) && $post->post_type === 'templately_library'){
				if(in_array(get_post_meta($post->ID, '_templately_template_type', true), ['header', 'footer'])){
					return '';
				}
			}
		}
		return $content;
	}

}