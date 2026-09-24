<?php
/**
 * Deactivation-survey module.
 *
 * Asks why, once, on the way out: intercepts the Deactivate link on plugins.php,
 * offers an optional reason + free-text note, then lets the deactivation proceed.
 * Ported from `latest`, where it lived as `includes/Core/DeactivationSurvey.php`
 * plus a `react-src/deactivate-survey/` entry — neither of which has a home in the
 * modular layout, so the feature becomes its own module here.
 *
 * Self-contained on purpose: it owns its endpoint, its bundle, and its enqueue.
 * Nothing else in the plugin runs on plugins.php, and nothing here runs anywhere
 * else.
 *
 * @package Templately
 */

namespace Templately\Modules\DeactivationSurvey;

use Templately\Core\Module_Base;
use Templately\Modules\DeactivationSurvey\REST\Survey;

class Module extends Module_Base {

	public function get_name(): string {
		return 'deactivation-survey';
	}

	protected function init_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function register_rest_routes(): void {
		Survey::get_instance()->register_routes();
	}

	/**
	 * Load the survey on the plugins screen, and only there.
	 *
	 * Deliberately its own `admin_enqueue_scripts` callback rather than an entry in
	 * `Core\Admin`'s allowed-hooks list: that list governs the SPA's assets, and
	 * adding plugins.php to it would pull the whole admin bundle onto a screen that
	 * needs a 30KB widget. The module owning its own enqueue is also what keeps
	 * this feature deletable in one directory.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook = '' ) {
		if ( 'plugins.php' !== $hook ) {
			return;
		}

		if ( ! current_user_can( 'deactivate_plugins' ) ) {
			return;
		}

		templately()->assets->enqueue(
			'templately-inter',
			set_url_scheme( '//fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap' )
		);
		/**
		 * plugins.php is not our screen, so the survey gets the SCOPED Tailwind build,
		 * never `css/tailwind.css`. The unscoped bundle is compiled with
		 * `important: true` and emits its utilities unlayered, so every name WordPress
		 * also uses won the cascade: `.inline` beat `.notice.inline` and `.hidden`,
		 * collapsing the update notice in each plugin row to a coloured sliver and
		 * revealing the auto-update error placeholders meant to stay hidden.
		 * `css/tailwind-survey.css` is the same source and config with every selector
		 * prefixed by `.templately-scope`, the container the modal portal mounts into.
		 */
		templately()->assets->enqueue( 'templately-tailwind-survey', 'css/tailwind-survey.css', [ 'templately-inter' ] );
		templately()->assets->enqueue( 'templately-deactivation-survey', 'css/deactivation-survey-style.css', [ 'templately-tailwind-survey' ] );
		templately()->assets->enqueue( 'templately', 'js/deactivation-survey.js', [ 'regenerator-runtime' ], true );
		templately()->assets->localize( 'templately', 'templatelyDeactivationSurvey', [
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'restUrl'        => esc_url_raw( rest_url( 'templately/v1/deactivation-feedback' ) ),
			'pluginBasename' => TEMPLATELY_PLUGIN_BASENAME,
			'reasons'        => Survey::get_reasons_for_js(),
		] );
	}
}
