<?php
namespace Templately\Core;

use Templately\Utils\Base;

abstract class Platform extends Base {
    // protected $module;

//    public function __construct() {
//        // $this->module = Module::get_instance();
//    }

    // abstract public function get_saved_templates( $params = [] );
    // abstract public function delete( $params = [] );
    abstract public function create_page( $id, $title, $importer = null, $settings = [] );

	/**
	 * Resolve remote template_type to the correct Builder template type.
	 * Maps API template types to internal Builder keys with fallback to page_single.
	 *
	 * @param array $template_data Template data from API containing 'template_type' key
	 *
	 * @return string Builder template type key
	 */
	protected static function resolve_library_type( array $template_data ): string {
		$template_type = (string) ( $template_data['template_type'] ?? '' );

		if ( $template_type === '' ) {
			throw new \InvalidArgumentException( "template_type is required for library import." );
		}

		// Direct match: if the slug is already a registered Builder type key, use it as-is
		// (e.g. 'header', 'footer', 'archive', 'search', 'docs_single', 'docs_archive', etc.)
		$registered_types = templately()->theme_builder::$templates_manager->get_template_types();
		if ( isset( $registered_types[ $template_type ] ) ) {
			return $template_type;
		}

		// Translation map for API slugs that differ from their Builder type key
		$map = [
			'single-post'            => 'single',
			'single-doc'             => 'docs_single',
			'single-product'         => 'product_single',
			'fluent-product-single'  => 'fluent_product_single',
			'docs-archive'           => 'docs_archive',
			'course-archive'         => 'course_archive',
			'product-archive'        => 'product_archive',
			'404'                    => 'error',
		];

		if ( isset( $map[ $template_type ] ) ) {
			return $map[ $template_type ];
		}

		throw new \InvalidArgumentException( "Unknown template_type '{$template_type}' — no matching Builder type." );
	}
}