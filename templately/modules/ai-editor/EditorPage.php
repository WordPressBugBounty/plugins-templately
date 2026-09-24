<?php

namespace Templately\Modules\AiEditor;

use Templately\Utils\Base;
use Templately\Utils\Helper;

/**
 * The Templately AI Editor page (041 phase 1): a hidden, fullscreen admin page
 * that iframes the post's NATIVE editor (post.php?action=elementor|edit) with
 * the AI chat auto-docked inside via the templately_host=1 URL signal. The
 * iframe loads the correct editor assets itself — no re-enqueueing, no
 * embedded-editor maintenance; the shell is plain PHP + inline CSS.
 */
class EditorPage extends Base {

	const SLUG = 'templately-ai-editor';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		add_filter( 'post_row_actions', [ $this, 'row_action' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'row_action' ], 10, 2 );
	}

	/**
	 * Hidden page: empty parent slug registers the page + its hook
	 * (admin_page_{slug}) with NO menu entry. Never register-then-remove —
	 * remove_submenu_page() strips the entry user_can_access_admin_page()
	 * validates against, turning the page into a core 403.
	 */
	public function admin_menu() {
		add_submenu_page(
			'',
			__( 'AI Editor', 'templately' ),
			__( 'AI Editor', 'templately' ),
			'delete_posts',
			self::SLUG,
			[ $this, 'display' ]
		);
	}

	/**
	 * The canonical URL of the editor page for a post.
	 */
	public static function url( $post_id ) {
		return add_query_arg(
			[
				'page' => self::SLUG,
				'post' => absint( $post_id ),
			],
			admin_url( 'admin.php' )
		);
	}

	public function body_class( $classes ) {
		if ( $this->is_current_screen() ) {
			$classes .= ' templately-ai-editor-page';
		}
		return $classes;
	}

	private function is_current_screen() {
		return is_admin() && isset( $_GET['page'] ) && self::SLUG === $_GET['page'];
	}

	public function display() {
		$post_id = absint( $_GET['post'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this page with Templately AI.', 'templately' ) );
		}

		$platform  = $this->detect_platform( $post );
		$edit_link = get_edit_post_link( $post_id, 'raw' );

		if ( null === $platform ) {
			// Classic-editor-only content — never iframe it; hand off cleanly.
			wp_die(
				sprintf(
					/* translators: %s: edit-post URL */
					wp_kses( __( 'This content uses the classic editor, which Templately AI cannot host. <a href="%s">Open it in its own editor</a>.', 'templately' ), [ 'a' => [ 'href' => [] ] ] ),
					esc_url( $edit_link )
				),
				esc_html__( 'Templately AI Editor', 'templately' )
			);
		}

		$native_url = add_query_arg(
			[
				'post'   => $post_id,
				'action' => 'elementor' === $platform ? 'elementor' : 'edit',
			],
			admin_url( 'post.php' )
		);

		Helper::views(
			'ai-editor-page',
			[
				'src'         => add_query_arg( 'templately_host', 1, $native_url ),
				'exit_url'    => $native_url,
				'exit_wp_url' => $this->wordpress_exit_url( $post ),
				'post_title'  => get_the_title( $post ),
				'platform'    => $platform,
			]
		);
	}

	/**
	 * Where "Exit to WordPress" goes: the list screen for THIS post's type when
	 * the user arrived from somewhere in wp-admin (Posts → edit.php, Pages →
	 * edit.php?post_type=page, other CPTs likewise); the main dashboard when the
	 * editor was opened cold (new tab / typed URL, so there is no referrer to
	 * come back to).
	 */
	private function wordpress_exit_url( $post ) {
		if ( ! wp_get_referer() ) {
			return admin_url( 'index.php' );
		}
		$post_type = get_post_type( $post ) ?: 'post';
		return 'post' === $post_type
			? admin_url( 'edit.php' )
			: add_query_arg( 'post_type', $post_type, admin_url( 'edit.php' ) );
	}

	/**
	 * 'elementor' | 'gutenberg' | null (classic-only). Same signal
	 * Elementor's Document::is_built_with_elementor() reads.
	 */
	private function detect_platform( $post ) {
		if ( did_action( 'elementor/loaded' ) && 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			return 'elementor';
		}
		if ( use_block_editor_for_post( $post ) ) {
			return 'gutenberg';
		}
		return null;
	}

	/**
	 * "Edit with Templately AI" on the posts/pages list rows.
	 */
	public function row_action( $actions, $post ) {
		if (
			current_user_can( 'edit_post', $post->ID ) &&
			'trash' !== $post->post_status &&
			null !== $this->detect_platform( $post )
		) {
			$actions['templately_ai_edit'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::url( $post->ID ) ),
				esc_html__( 'Edit with Templately AI', 'templately' )
			);
		}
		return $actions;
	}
}
