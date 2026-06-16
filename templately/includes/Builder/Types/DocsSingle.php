<?php

namespace Templately\Builder\Types;

class DocsSingle extends Single {
	public static function get_type(): string {
		return 'docs_single';
	}

	public static function get_title(): string {
		return __( 'Doc Single', 'templately' );
	}

	public static function get_plural_title(): string {
		return __( 'Docs Single', 'templately' );
	}

	public static function get_properties( $import_settings = [] ): array {
		$properties = parent::get_properties();

		$properties['condition'] = 'include/singular/docs';
		$properties['builder']   = post_type_exists( 'docs' );

		return $properties;
	}
}
