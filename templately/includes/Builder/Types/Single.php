<?php

namespace Templately\Builder\Types;

class Single extends ThemeTemplate {
	public static function get_type(): string {
		return 'single';
	}

	public static function get_title(): string {
		return __( 'Single', 'templately' );
	}

	public static function get_plural_title(): string {
		return __( 'Singles', 'templately' );
	}

	public static function get_properties($import_settings = []): array {
		$properties = parent::get_properties();

		$properties['location']                  = 'single';
		$properties['condition']                 = 'include/singular/post';
		$properties['support_wp_page_templates'] = true;

		if(!empty($import_settings["sub_type"])){
			$properties['condition'] = 'include/singular/' . $import_settings["sub_type"];
		}

		return $properties;
	}
}