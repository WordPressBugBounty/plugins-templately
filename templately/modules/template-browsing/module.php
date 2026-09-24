<?php
/**
 * Template-browsing module (spec 017-template-browsing).
 *
 * The template library discovery surface: items listing/search/counts/featured/
 * trending plus the public metadata endpoints (categories, tags, template types).
 * Relocated from includes/API/ — behavior byte-identical (same routes, params,
 * transient cache keys). The Dependencies endpoint stays with 020-single-import
 * per the PRD ownership ruling.
 *
 * @package Templately
 */

namespace Templately\Modules\TemplateBrowsing;

use Templately\Core\Module_Base;
use Templately\Modules\TemplateBrowsing\REST\Categories;
use Templately\Modules\TemplateBrowsing\REST\Items;
use Templately\Modules\TemplateBrowsing\REST\Tags;
use Templately\Modules\TemplateBrowsing\REST\TemplateTypes;

class Module extends Module_Base {

	public function get_name(): string {
		return 'template-browsing';
	}

	public function get_dependencies(): array {
		// items/favourite + items/rating delegate to the shared permission chain that
		// auth's session state feeds; auth must be booted first (spec 017 contract).
		return [ 'auth' ];
	}

	public function register_rest_routes(): void {
		Items::get_instance()->register_routes();
		Categories::get_instance()->register_routes();
		Tags::get_instance()->register_routes();
		TemplateTypes::get_instance()->register_routes();
	}
}
