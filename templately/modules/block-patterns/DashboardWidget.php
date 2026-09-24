<?php

namespace Templately\Modules\BlockPatterns;

use Templately\Utils\Base;

/**
 * "New from Templately" dashboard widget.
 *
 * The only surface in this module that reaches users BETWEEN editing sessions.
 * Renders exclusively from the local pattern-list cache — a dashboard visit
 * must never cost a remote call (spec 051 FR-009). Server-rendered: no bundle,
 * no REST (research D9).
 */
class DashboardWidget extends Base {

	const WIDGET_ID = 'templately_block_patterns';
	const MAX_ITEMS = 6;

	public function register_widget(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// wp_dashboard_setup always runs with this loaded; the guard keeps the
		// method callable outside a dashboard request (tests, wp-cli).
		if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'New from Templately', 'templately' ),
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		$list  = PatternSync::get_instance()->get_list();
		$items = is_array( $list ) && ! empty( $list['items'] ) ? array_slice( $list['items'], 0, self::MAX_ITEMS ) : [];

		if ( empty( $items ) ) {
			// Cold cache is a normal state, not a failure — say nothing alarming.
			printf(
				'<p>%s</p>',
				esc_html__( 'Templately designs will appear here once your library has synced.', 'templately' )
			);
			$this->render_library_link();

			return;
		}

		echo '<ul>';
		foreach ( $items as $item ) {
			if ( empty( $item['title'] ) ) {
				continue;
			}
			printf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( $this->library_url( $item['kind'] ?? 'section' ) ),
				esc_html( $item['title'] )
			);
		}
		echo '</ul>';

		$this->render_library_link();
	}

	private function render_library_link(): void {
		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( $this->library_url( 'section' ) ),
			esc_html__( 'Browse the Templately library', 'templately' )
		);
	}

	/**
	 * Deep link into the SPA. The route string must match a path the app
	 * actually registers (Constitution VIII, cross-module route contract) —
	 * `?page=templately&path=/gutenberg/{type}` is that scheme.
	 */
	private function library_url( string $kind ): string {
		$type = 'page' === $kind ? 'pages' : 'blocks';

		return add_query_arg(
			[
				'page' => 'templately',
				'path' => "/gutenberg/{$type}",
			],
			admin_url( 'admin.php' )
		);
	}
}
