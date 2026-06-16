<?php

namespace Templately\Builder\Types;

class DocsArchive extends Archive {
	public static function get_type(): string {
		return 'docs_archive';
	}

	public static function get_title(): string {
		return __( 'Doc Archive', 'templately' );
	}

	public static function get_plural_title(): string {
		return __( 'Docs Archives', 'templately' );
	}

	public static function get_properties( $import_settings = [] ): array {
		$properties = parent::get_properties();

		$properties['condition'] = 'include/archive/docs_archive';
		$properties['builder']   = post_type_exists( 'docs' );

		return $properties;
	}
}
