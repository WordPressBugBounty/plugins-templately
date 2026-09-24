<?php
/**
 * Template-details module (spec 018-template-details).
 *
 * Owns the Checkout endpoint (purchase/upgrade redirect) plus the detail-fetch and
 * related-templates sagas. The detail item-fetch/favourite/rating REST endpoints are
 * NOT owned here — they are registered by modules/template-browsing (Items.php); this
 * module's register_rest_routes() intentionally covers only Checkout.
 *
 * @package Templately
 */

namespace Templately\Modules\TemplateDetails;

use Templately\Core\Module_Base;
use Templately\Modules\TemplateDetails\REST\Checkout;

class Module extends Module_Base {

	public function get_name(): string {
		return 'template-details';
	}

	public function register_rest_routes(): void {
		Checkout::get_instance()->register_routes();
	}
}
