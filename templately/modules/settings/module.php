<?php
/**
 * Settings module (spec 015-settings-page).
 *
 * The wp-admin Settings submenu page, the settings/update REST endpoint, and the
 * public-page custom-CSS injection. Relocated from includes/Admin/ — behavior
 * byte-identical (same submenu, same route, same wp_head output).
 *
 * @package Templately
 */

namespace Templately\Modules\Settings;

use Templately\Core\Module_Base;
use Templately\Modules\Settings\REST\Preferences as PreferencesEndpoint;
use Templately\Modules\Settings\REST\Settings as SettingsEndpoint;

class Module extends Module_Base {

	public function get_name(): string {
		return 'settings';
	}

	public function get_dependencies(): array {
		// REST/Settings.php calls KitApplier::apply() from modules/elementor-kit-settings
		// (spec 031) when persisting global colors/typography.
		return [ 'elementor-kit-settings' ];
	}

	protected function init_hooks(): void {
		// Registers admin_menu (Settings submenu) + wp_head (custom CSS) — both hooks
		// fire after plugins_loaded, so boot-time registration is timing-identical to
		// the old Plugin-constructor instantiation.
		Settings::get_instance();

		// Core's Admin::scripts() localizes window.templately; the module contributes
		// its per-user pref through the existing filter seam (core never imports us).
		add_filter( 'templately_admin_localized_data', function ( $data ) {
			$data['editor_default_tab'] = Preferences::get_editor_default_tab();

			return $data;
		} );
	}

	public function register_rest_routes(): void {
		SettingsEndpoint::get_instance()->register_routes();
		PreferencesEndpoint::get_instance()->register_routes();
	}
}
