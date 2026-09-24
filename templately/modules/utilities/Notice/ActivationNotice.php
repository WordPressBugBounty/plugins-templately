<?php
/**
 * The one-time "turn on cleanup" prompt.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Notice;

use Templately\Modules\Utilities\Cleanup\Activation;

/**
 * Lives OUTSIDE the settings page on purpose.
 *
 * The activation gate means an unactivated site reclaims nothing, ever — that
 * was a deliberate decision, and its whole cost falls on administrators who
 * never open the plugin's settings. If the only way to discover the prompt were
 * inside the tab, those are exactly the sites that would keep leaking.
 *
 * Dismissal hides the notice but is NOT consent: the tab keeps showing the
 * pending state and the prompt remains reachable, so dismissing once cannot
 * silently strand a site in "nothing is ever reclaimed and nothing says so".
 */
final class ActivationNotice {

	public static function boot(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only when there is genuinely something to reclaim — a site with a clean
		// uploads directory should never see this.
		if ( ! Activation::should_prompt() ) {
			return;
		}

		$state    = Activation::state();
		// The settings SPA is its OWN admin page (`templately_settings`), not a
		// path inside the template library. Pointing at the library page lands on
		// the router's catch-all and renders an unrelated view — verified in a
		// live instance, which is the only place that shows up.
		$settings = admin_url( 'admin.php?page=templately_settings&path=settings/elementor/utilities' );

		printf(
			'<div class="notice notice-info"><p>%s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: reclaimable size, e.g. "412 MB". */
					__( 'Templately can reclaim about %s of leftover import files. Nothing is removed until you turn cleanup on.', 'templately' ),
					size_format( (int) $state['last_estimate_bytes'] )
				)
			),
			esc_url( $settings ),
			esc_html__( 'Review and turn on cleanup', 'templately' )
		);
	}
}
