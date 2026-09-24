<?php

namespace Templately\Modules\ThemeBuilder;

use Templately\Utils\Helper;
use WP_Post;
use Templately\Admin\Roles;
use WP_Query;

use Templately\Modules\ThemeBuilder\Managers\TemplateManager;

class Source {
	const CPT                    = 'templately_library';
	const TYPE_META_KEY          = '_templately_template_type';
	const PLATFORM_META_KEY      = '_templately_template_platform';
	/**
	 * Marks the template that wins a TIE for its type.
	 *
	 * A tie-break, never a selector: display conditions still decide which templates are
	 * eligible and which is most specific (a shop-archive header beats a site-wide one on
	 * shop pages, flag or no flag). This only settles the case conditions cannot — two
	 * templates matching the same location with the SAME specificity — which used to fall
	 * to "lowest post ID", i.e. the OLDEST import won and a freshly imported header was
	 * never seen. One template per type carries it; the list marks it "Active".
	 */
	const ACTIVE_META_KEY        = '_templately_is_active_template';

	public $post_type_object;

	/**
	 * @var ThemeBuilder
	 */
	protected $builder;

	public function __construct( $builder ) {
		$this->builder = $builder;

		$this->add_actions();
		$this->register_post_type();
	}

	private function add_actions() {
		add_filter( 'views_edit-' . self::CPT, [ $this, 'admin_print_tabs' ], 100 );
		add_filter( 'post_row_actions', [ $this, 'row_actions' ], 10, 2 );
		add_action( 'admin_post_templately_set_active_template', [ $this, 'handle_set_active' ] );
		add_action( 'in_admin_header', [ $this, 'in_admin_header' ] );
		if ( is_admin() ) {
			// add_action( 'manage_posts_extra_tablenav', [ $this, 'extra_table_nav' ] );
			add_action( 'manage_' . self::CPT . '_posts_custom_column', [ $this, 'custom_column' ], 10, 2 );
			add_filter( 'manage_' . self::CPT . '_posts_columns', [ $this, 'custom_columns' ] );
			add_filter( 'post_row_actions', [ $this, 'post_row_actions' ], 10, 2 );
			add_action( 'admin_footer', [ $this, 'app' ] );
		}

		// add_action( 'save_post', [ $this, 'save_post' ], 11, 3 );
	}

	/**
	 * If needed to add a filter for platform
	 *
	 * @param $which
	 *
	 * @return void
	 * @suppress 50
	 */
	public function extra_table_nav( $which ) {
		if( ! $this->is_edit_screen() ) {
			return;
		}

		// if( $which === 'top' ) {
		//
		// }
	}

	/**
	 * Offer "Make active" on every template that is not already the active one.
	 *
	 * A read-only badge would leave a wrong choice fixable only by re-importing, so the
	 * flag the import sets is the same one the user can set.
	 *
	 * @param array    $actions
	 * @param \WP_Post $post
	 * @return array
	 */
	public function row_actions( $actions, $post ) {
		if ( ! $post || self::CPT !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$type = (string) get_post_meta( $post->ID, self::TYPE_META_KEY, true );

		// Already the one being served — nothing to make active.
		if ( '' === $type || TemplateManager::get_active_id( $type ) === (int) $post->ID ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=templately_set_active_template&post=' . $post->ID ),
			'templately_set_active_' . $post->ID
		);

		$actions['templately_set_active'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Make active', 'templately' )
		);

		return $actions;
	}

	/**
	 * Apply "Make active" and return to the list.
	 *
	 * Capability AND nonce: this changes which template every visitor is served, so it is
	 * not a link anything may follow on the user's behalf.
	 *
	 * @return void
	 */
	public function handle_set_active() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		if ( ! $post_id || self::CPT !== get_post_type( $post_id ) ) {
			wp_die( esc_html__( 'Invalid template.', 'templately' ) );
		}

		check_admin_referer( 'templately_set_active_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'templately' ) );
		}

		$this->builder::$templates_manager->set_active( $post_id );

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=' . self::CPT ) );
		exit;
	}

	/**
	 * Print custom column data (Template Type and Platform)
	 *
	 * @param $column_name
	 * @param $post_id
	 *
	 * @return void
	 */
	public function custom_column( $column_name, $post_id ) {
		switch ( $column_name ) {
			case 'template_type':
				$types = $this->builder::$templates_manager->get_template_types();
				$type  = get_post_meta( $post_id, self::TYPE_META_KEY, true );
				if(isset($types[ $type ])){
					echo esc_html( call_user_func( [ $types[ $type ], 'get_title' ] ) );
				}
				// With several templates of one type there was nothing on screen saying which
				// one a visitor actually gets. Badge the EFFECTIVE winner, not merely the
				// flagged one: nothing is flagged on any site that predates the flag, so a
				// badge keyed on it would appear nowhere while two headers sit here and one
				// of them quietly wins. One badge, whether the template was chosen or is
				// simply the newest — the reader wants to know which one is live, and a
				// second variant for "how it got there" asks them to learn a distinction
				// that changes nothing they would do about it.
				if ( '' !== $type && TemplateManager::get_active_id( $type ) === (int) $post_id ) {
					printf(
						' <span class="templately-active-badge" style="display:inline-block;margin-left:6px;padding:1px 8px;border-radius:9px;background:#e7f7ee;color:#0a6b3d;font-size:11px;font-weight:600;line-height:1.8;vertical-align:middle;" title="%s">%s</span>',
						esc_attr__( 'The template used for this type. Display conditions can still override it on specific pages.', 'templately' ),
						esc_html__( 'Active', 'templately' )
					);
				}
				break;
			case 'template_platform':
				echo esc_html( get_post_meta( $post_id, self::PLATFORM_META_KEY, true ) );
				break;
		}
	}

	/**
	 * Add custom columns. (Template Type and Platform)
	 *
	 * @param $columns
	 *
	 * @return mixed
	 */
	public function custom_columns( $columns ) {
		$old_columns = $columns;

		unset( $columns['date'] );
		unset( $columns['author'] );

		$columns['template_type']     = __( 'Type', 'templately' );
		$columns['template_platform'] = __( 'Platform', 'templately' );

		$columns['author'] = $old_columns['author'];
		$columns['date']   = $old_columns['date'];

		return $columns;
	}

	/**
	 * Add New Template Button App
	 * @return void
	 */
	public function in_admin_header() {
		if ( ! $this->is_edit_screen() ) {
			return;
		}

		echo '<div id="templately-theme-builder-admin-header"></div>';
	}

	/**
	 * Edit conditions button app
	 * @return void
	 */
	public function app() {
		if( ! $this->is_edit_screen() ) {
			return;
		}

		Helper::views( 'builder/edit-conditions' );
	}

	private function is_edit_screen(): bool {
		global $current_screen;

		if ( ! $current_screen ) {
			return false;
		}

		return 'edit' === $current_screen->base && self::CPT === $current_screen->post_type;
	}

	public function post_row_actions( $actions, $post ) {
		if ( $this->is_edit_screen() ) {
			$actions['edit-conditions'] = sprintf( '<a data-template_id="%1$s" class="templately-edit-conditions" href="#">%2$s</a>', $post->ID, esc_html__( 'Edit Conditions', 'templately' ) );
		}

		return $actions;
	}

	public function save_post( int $post_id, WP_Post $post, bool $update ) {
		if ( $post->post_type !== self::CPT ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// FIXME: this is something I need to fix in the future.
	}

	/**
	 * Register post type
	 *
	 * @return void
	 */
	public function register_post_type() {
		$name = esc_html_x( 'Templates', 'Template Library', 'templately' );

		$labels = [
			'name'               => $name,
			'singular_name'      => esc_html_x( 'Template', 'Template Library', 'templately' ),
			'add_new'            => esc_html_x( 'Add New Template', 'Template Library', 'templately' ),
			'add_new_item'       => esc_html_x( 'Add New Template', 'Template Library', 'templately' ),
			'edit_item'          => esc_html_x( 'Edit Template', 'Template Library', 'templately' ),
			'new_item'           => esc_html_x( 'New Template', 'Template Library', 'templately' ),
			'all_items'          => esc_html_x( 'All Templates', 'Template Library', 'templately' ),
			'view_item'          => esc_html_x( 'View Template', 'Template Library', 'templately' ),
			'search_items'       => esc_html_x( 'Search Template', 'Template Library', 'templately' ),
			'not_found'          => esc_html_x( 'No Templates found', 'Template Library', 'templately' ),
			'not_found_in_trash' => esc_html_x( 'No Templates found in Trash', 'Template Library', 'templately' ),
			'parent_item_colon'  => '',
			'menu_name'          => esc_html_x( 'Templates', 'Template Library', 'templately' ),
		];

		$args = [
			'labels'              => $labels,
			'public'              => true,
			'rewrite'             => false,
			'menu_icon'           => 'dashicons-admin-page',
			'show_ui'             => true,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'exclude_from_search' => true,
			// Site-structure control, not post authoring: every write/list primitive
			// resolves to `edit_templately_builder` (administrator-only by default).
			// See Templately\Admin\Roles.
			'capability_type'     => 'post',
			'capabilities'        => Roles::builder_post_type_capabilities(),
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'show_in_rest'        => true,
			'supports'            => [ 'title', 'thumbnail', 'author', 'editor', 'elementor' ],
		];

		/**
		 * Register template library post type args.
		 *
		 * @param array $args Arguments for registering a post type.
		 */

		$this->post_type_object = register_post_type( self::CPT, $args );
	}

	/**
	 * Tab menu
	 *
	 * @param $tabs
	 *
	 * @return void
	 */
	public function admin_print_tabs( $tabs ) {
		$types = templately()->theme_builder::$templates_manager->get_template_types();

		$template_types = [];
		$status_args    = [ 'post_type' => 'templately_library' ];

		$template_types['all'] = [
			'url'   => add_query_arg( $status_args, 'edit.php' ),
			'label' => __( 'All', 'templately' )
		];

		foreach ( $types as $type_name => $type ) {
			if( $type::get_property( 'builder' ) === false ) {
				continue;
			}

			$status_args['type']          = $type_name;
			$template_types[ $type_name ] = [
				'url'   => add_query_arg( $status_args, 'edit.php' ),
				'label' => call_user_func( [ $type, 'get_title' ] )
			];
		}

		$this->builder::$views->get( 'builder/tabs', [ 'tabs' => $tabs, 'template_types' => $template_types ] );
		return [];
	}

	/**
	 * Retrieves items.
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public function get_items( array $args = [] ): array {
		$template_types = [];

		if ( ! empty( $args['type'] ) ) {
			$template_types = $args['type'];
			unset( $args['type'] );
		}

		$defaults_args = [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => - 1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		if ( ! empty( $template_types ) ) {
			$defaults_args['meta_query'] = [
				[
					'key'   => self::TYPE_META_KEY,
					'value' => $template_types,
				]
			];
		}

		$args  = wp_parse_args( $args, $defaults_args );
		$items = new WP_Query( $args );

		return $items->posts;
	}
}