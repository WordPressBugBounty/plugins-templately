<?php
/**
 * Template-recommendations module (spec 019-template-recommendations).
 *
 * FRONTEND-ONLY: its Ownership section is explicit that all three REST endpoints
 * consumed here (related-items, featured-items, trending-items) are registered by
 * modules/template-browsing (spec 017) in REST/Items.php — this module MUST NOT ship
 * REST controller classes of its own. register_rest_routes() is intentionally a no-op.
 *
 * @package Templately
 */

namespace Templately\Modules\TemplateRecommendations;

use Templately\Core\Module_Base;

class Module extends Module_Base {

	public function get_name(): string {
		return 'template-recommendations';
	}

	public function get_dependencies(): array {
		// CONTRACT deps, not import deps (the dependency scanner rightly finds no
		// code reference): template-browsing owns the three REST endpoints this
		// module's strips consume, and template-details renders the slot fills
		// this module registers. Both must be booted for this module to mean
		// anything — same pattern as template-browsing's own auth dep.
		return [ 'template-details', 'template-browsing' ];
	}
}
