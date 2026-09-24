<?php

namespace Templately\Modules\ThemeBuilder\Factory;

use Elementor\Core\Base\Document;
use Templately\Modules\ThemeBuilder\Source;

class TemplateFactory {
	protected $platform;
	protected $template_manager;

	public function __construct( $platform = 'elementor' ) {
		$this->platform         = $platform;
		$this->template_manager = templately()->theme_builder::$templates_manager;
	}

	public function create( $type, $data, $meta = [] ) {
		$data = wp_parse_args( $data );

		$meta = wp_parse_args( $meta, [
			Source::PLATFORM_META_KEY => $this->platform,
			Source::TYPE_META_KEY     => $type,
		] );

		// Guard against Elementor not being loaded: referencing the Document constant
		// on a missing class is an UNCATCHABLE "Class not found" fatal, which aborted
		// the whole import here rather than anywhere it could be reported.
		if ( $this->platform === 'elementor' && class_exists( Document::class ) ) {
			$meta[ Document::BUILT_WITH_ELEMENTOR_META_KEY ] = 'builder';
		}

		return $this->template_manager->create( $type, $data, $meta );
	}
}
