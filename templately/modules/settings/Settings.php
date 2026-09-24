<?php

namespace Templately\Modules\Settings;

use Templately\Utils\Base;
use Templately\Utils\Helper;

class Settings extends Base  {

	public function __construct() {
		add_action('admin_menu', [$this, 'admin_menu']);
		add_action('wp_head', [$this, 'add_custom_css']);
	}

	public function admin_menu() {
		// No $position — see the note in Core/Admin.php::admin_menu(). The old
		// '58.7' was a copied top-level slot, past the end of this submenu, so
		// it always appended; below WP 6.0 it also warned on every admin load.
		add_submenu_page( 'templately', 'Settings', 'Settings', 'delete_posts', 'templately_settings', [$this, 'display_settings'] );
	}


	public function display_settings() {
		Helper::views( 'settings' );
	}

	public function add_custom_css() {
		$custom_css = get_option('templately_custom_css');
		if($custom_css){
			// Strip tags before echoing INSIDE the <style> element: a stored value
			// containing `</style><script>…` would otherwise break out of it.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the wrapper is a literal; the value is tag-stripped above.
			echo "\n<style id='templately-custom-css'>\n" . wp_strip_all_tags( $custom_css ) . "\n</style>\n";
		}
	}
}
