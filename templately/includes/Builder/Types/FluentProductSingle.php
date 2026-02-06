<?php

namespace Templately\Builder\Types;

class FluentProductSingle extends Single {
	public static function get_type(): string {
		return 'fluent_product_single';
	}

	public static function get_title(): string {
		return __( 'Fluent Cart Product Single', 'templately' );
	}

	public static function get_plural_title(): string {
		return __( 'Fluent Cart Products Single', 'templately' );
	}

	public static function get_properties($import_settings = []): array {
		$properties = parent::get_properties();

		$properties['condition'] = 'include/singular/fluent-products';
		$properties['builder']   = post_type_exists( 'fluent-products' );

		return $properties;
	}
}