<?php

namespace Templately\Modules\Settings\REST;

use Templately\Admin\API\BaseAPI;
use Templately\Modules\ElementorKitSettings\Utils\KitApplier;
use Templately\Utils\Helper;
use Templately\Utils\Response\FatalGuard;

use WP_REST_Request;

class Settings extends BaseAPI {
	private $endpoint = 'settings';



	/**
	 * @param $request WP_REST_Request for getting all route request in time.
	 *
	 * @return WP_Error|boolean
	 */
	public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;
		if(!current_user_can('manage_options')){
			return false;
		}
		return true;
	}

	public function register_routes() {
		$this->post( $this->endpoint . '/update', [$this, 'update_settings'] );
	}

	public function update_settings() {
		// A PHP fatal raised by a builder further down this method (see contain())
		// otherwise reaches the browser as WP core's "critical error" page, which the
		// settings screen cannot parse. Armed so it comes back as the 043 envelope.
		FatalGuard::arm( 'settings/update' );

		$_settings   = $this->request->get_param('settings');
		$platform    = $_settings['platform'] ?? '';
		$siteTitle   = $_settings['siteTitle'] ?? '';
		$siteTagline = $_settings['siteTagline'] ?? '';
		$customCSS   = $_settings['customCSS'] ?? '';

		$failed = [];

		// Written one at a time, each contained. `blogname` / `blogdescription` are the
		// two options Elementor hooks (it syncs both into the active kit), so on a site
		// with a broken kit the FIRST write used to throw and take the whole save with
		// it — the tagline, the custom CSS and every colour/typography assignment below
		// were silently never written. See contain().
		foreach ( [
			'blogname'              => $siteTitle,
			'blogdescription'       => $siteTagline,
			'templately_custom_css' => $customCSS,
		] as $key => $value ) {
			if ( ! $this->contain( "option:{$key}", function () use ( $key, $value ) {
				update_option( $key, $value );
			} ) ) {
				$failed[] = $key;
			}
		}

		if ( ! $this->contain( 'logo', [ $this, 'update_logo' ] ) ) {
			$failed[] = 'logo';
		}

		if($platform === 'elementor'){
			if(class_exists('Elementor\Plugin')){
				if ( ! $this->contain( 'elementor-kit', function () use ( $_settings ) {
					KitApplier::apply($_settings);
				} ) ) {
					$failed[] = 'kit';
				}
			}
		}
		else if($platform === 'gutenberg'){
			if ( ! $this->contain( 'gutenberg-settings', function () use ( $_settings ) {
				$this->update_gutenberg_settings($_settings);
			} ) ) {
				$failed[] = 'global-styles';
			}
		}

		$response = [ 'message' => 'Settings updated successfully' ];

		// Named so the client can tell the user WHICH parts did not stick. An empty
		// list is omitted, so the shape is unchanged for the ordinary save.
		if ( ! empty( $failed ) ) {
			$response['failed'] = $failed;
		}

		return $this->success( $response );
	}

	/**
	 * Run one settings write, contained.
	 *
	 * Every write below reaches third-party code: `update_option()` fires that option's
	 * hooks (Elementor listens on `update_option_blogname`/`update_option_blogdescription`
	 * and writes the value into the ACTIVE KIT), and the kit/global-style writers call
	 * into the builder directly. On a site whose Elementor kit post is gone — a bad
	 * restore, a deleted kit, a half-finished migration — that path throws
	 * `Exception( 'Invalid post.' )` from Elementor's page settings manager: `Kit`
	 * documents for a missing post carry an empty `WP_Post`, so `save_settings()` asks
	 * `ajax_before_save_settings()` to validate post ID `0`.
	 *
	 * Uncontained, an exception in a REST callback is an uncaught PHP fatal — WP does not
	 * catch these during dispatch, and `RestEnvelope` runs on `rest_post_dispatch`, which
	 * never sees it. The user got a raw 500 with nothing saved, from the very first write.
	 *
	 * Each write is therefore independent: a failure is logged with its section name,
	 * reported in the response's `failed` list, and the remaining sections still run.
	 * `\Throwable` rather than `Exception` because the same code raises PHP `\Error` too
	 * (an add-on referencing a class Elementor has since removed).
	 *
	 * @param string   $section Log/label for the write ("option:blogname", "logo", …).
	 * @param callable $write   The write itself.
	 *
	 * @return bool True when it completed without raising.
	 */
	private function contain( $section, callable $write ): bool {
		try {
			$write();
			return true;
		} catch ( \Throwable $e ) {
			Helper::log(
				sprintf( 'Settings update failed for "%s": %s', $section, $e->getMessage() ),
				'settings',
				'error'
			);

			return false;
		}
	}

	public function update_logo() {
		$_settings   = $this->request->get_param('settings');
		$logo        = $_settings['logoImage'] ?? [];

		if(!empty($logo['id'])){
			set_theme_mod( 'custom_logo', $logo['id'] );
			update_option( 'site_logo', $logo['id'] );

			// Update Elementor kit if Elementor is enabled
			if(class_exists('Elementor\Plugin')){
				$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
				// `get_active_kit()` NEVER returns falsy — a site with no valid kit gets
				// an empty Kit instance (post id 0) whose update_settings() throws
				// `Invalid post.`. get_id() is the check that actually means "real kit".
				if($kit && $kit->get_id()){
					$settings = $kit->get_settings();
					$settings['site_logo'] = $logo;
					$kit->update_settings($settings);
				}
			}
		}
		else{
			remove_theme_mod( 'custom_logo' );
			delete_option( 'site_logo' );

			// Remove logo from Elementor kit if Elementor is enabled
			if(class_exists('Elementor\Plugin')){
				$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
				if($kit && $kit->get_id()){ // see the note on the branch above
					$settings = $kit->get_settings();
					unset($settings['site_logo']);
					$kit->update_settings($settings);
				}
			}
		}

		return true;
	}

	private function update_gutenberg_settings($_settings){
		$colors     = $_settings['colors'] ?? [];
		$typography = $_settings['typography'] ?? [];
		$settings   = get_option('eb_global_styles', []);
		$settings   = is_array($settings) ? $settings: [];
		$settings   = array_map(function($item) { return json_decode($item, true); }, $settings);


		if(!empty($colors)){
			if (isset($settings['global_colors'])) {
				foreach ($settings['global_colors'] as $key => $color) {
					$settings['global_colors'][$key]['color'] = $colors[$color['var']] ?? $color['color'];
				}
			}

			if (isset($settings['custom_colors'])) {
				foreach ($settings['custom_colors'] as $key => $color) {
					$settings['custom_colors'][$key]['color'] = $colors[$color['var']] ?? $color['color'];
				}
			}
		}

		if(!empty($typography)){
			$settings["global_typography"] = $typography;
		}

		$settings = array_map('json_encode', $settings);
		update_option('eb_global_styles', $settings);
	}
}
