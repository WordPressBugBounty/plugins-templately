<?php

namespace Templately\Modules\ImportRevert;

use Elementor\Plugin;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Utils\Response\AjaxResponder;
use Templately\Utils\Response\ErrorCode;

/**
 * RevertController — the "revert to old website" AJAX endpoint.
 *
 * Moved from modules/full-site-import/Ajax/ (2026-07-23, the split-modules
 * follow-up): revert reads FINISHED-import artifacts (`__templately_*` backup
 * options via FSI's `Utils::get_backup_options()`, `templately_fsi_imported_list`)
 * but shares no live import state with the runner pipeline — a real module
 * boundary. Still registered through FullSiteImport's nonce/capability AJAX
 * wrapper, via its `templately_fsi_ajax_handlers` filter seam (see module.php);
 * the `wp_ajax_templately_pack_import_revert` action name is unchanged.
 */
class RevertController {
	public function import_revert() {

		// // Get the nonce value from the request (usually from $_POST or $_GET)
		// $received_nonce = isset($_REQUEST['_wpnonce']) ? $_REQUEST['_wpnonce'] : '';

		// // Verify the nonce using wp_verify_nonce()
		// $verified = wp_verify_nonce($received_nonce, 'templately_pack_import_revert_nonce');

		// if (!$verified) {
		// 	wp_send_json_error("Nonce not verified.");
		// }

		delete_option('templately_import_platform');

		$option_active         = null;
		$options_deleted       = false;
		$imported_list_deleted = false;
		$options               = Utils::get_backup_options();
		$status_args           = [ 'post_type' => 'templately_library' ];
		$all_post_url          = add_query_arg( [
			"page" => "templately_settings",
			"path" => "settings/elementor/miscellaneous",
		], admin_url('admin.php' ));
		// wp_send_json_success([$options]);

		if(class_exists('Elementor\Plugin')){
			$kits_manager  = Plugin::$instance->kits_manager;
			$option_active = $kits_manager::OPTION_ACTIVE;
			$kit           = $kits_manager->get_active_kit();

			if ( ! $kit->get_id() ) {
				$kit = $kits_manager->create_default();
				update_option( $kits_manager::OPTION_ACTIVE, $kit );
			}
		}


		if (!empty($options) && is_array($options)) {
			foreach ($options as $key => $value) {
				if ('stylesheet' === $key) {
					if (get_option('stylesheet') !== $value) {
						switch_theme($value);
					}
				} else if($option_active === $key && class_exists('Elementor\Plugin')) {
					$kits_manager->revert( (int) $kits_manager->get_active_id(), (int) $value, 0 );
					$kit      = $kits_manager->get_active_kit();
					$settings = $kit->get_data('settings');
					if ( isset( $settings['site_logo'] ) ) {
						set_theme_mod( 'custom_logo', $settings['site_logo']['id'] );
					}
				} else {
					update_option($key, $value);
				}
				delete_option("__templately_$key");
				$options_deleted = true;
			}
		}

		$imported_list = get_option('templately_fsi_imported_list', []);
		if (!empty($imported_list) && is_array($imported_list)) {
			$_GET['force_delete_kit'] = 1; // Fallback GET Ready!
			foreach ($imported_list as $type => $list) {
				if (empty($list) || !is_array($list)) {
					continue;
				}
				// Loop through each item ID and delete it
				foreach ($list as $key => $item_id) {
					switch ($type) {
						case 'posts':
							// making sure default kit don't get deleted.
							if($option_active && isset($options[$option_active]) && $options[$option_active] == $item_id){
								break;
							}
							wp_delete_post($item_id, true); // Set true for permanent deletion
							break;
						case 'attachment':
							wp_delete_attachment($item_id, true); // Set true for permanent deletion
							break;
						case 'term':
							list($term_id, $taxonomy) = $item_id;
							wp_delete_term($term_id, $taxonomy); // Use corresponding taxonomy
							break;
						case 'taxonomy':
							// Taxonomies cannot be directly deleted. Consider de-registering it.
							break;
						case 'fluentform':
							if(class_exists('\FluentForm\App\Models\Form')){
								\FluentForm\App\Models\Form::remove($item_id);
							}
							break;
					}
				}
			}

			$imported_list_deleted = true;
			delete_option('templately_fsi_imported_list');
		}


		if($options_deleted || $imported_list_deleted){
			sleep(5);
			AjaxResponder::success([ 'options' => $options_deleted, 'imported_list' => $imported_list_deleted, 'site_url' => home_url(), 'redirect' => $all_post_url ]);
		}

		AjaxResponder::error(ErrorCode::SERVER_ERROR, __('Nothing was reverted.', 'templately'), [ 'options' => $options_deleted, 'imported_list' => $imported_list_deleted, 'site_url' => home_url() ]);
	}
	public static function has_revert(){
		$options = Utils::get_backup_options();
		$imported_list = get_option('templately_fsi_imported_list', []);
		if(!empty($options) || !empty($imported_list)){
			return true;
		}
		return false;
	}
}
