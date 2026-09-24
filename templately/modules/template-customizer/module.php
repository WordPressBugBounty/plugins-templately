<?php
/**
 * Template-customizer module (spec 021-template-customizer + 024-logo-branding).
 *
 * PHP REST surface: AI logo generation (generate/callback/data/poll/available-credits).
 * Relocated from includes/API/LogoGeneration.php — behavior byte-identical (same routes,
 * params, option keys). The consolidation decision assigns 024's logo REST here.
 *
 * The JS view layer this spec describes (Customizer wizard, color/typography editors,
 * logo generator UI, AI tagline UI) is DEFERRED: that code currently lives inside
 * react-src/app/components/fsi/{wizard,customizer}/ (spec 029's future territory),
 * tightly coupled to the FSI wizard's step machine (fsiFlowReducer, aiMode, SurfaceShell).
 * Extracting it now would mean restructuring FSI's wizard before 029's own pass — see the
 * modularization PRD's blockers log for the full reasoning. Complete the JS move when
 * spec 029 (or a dedicated follow-up after it) is reached.
 *
 * @package Templately
 */

namespace Templately\Modules\TemplateCustomizer;

use Templately\Core\Module_Base;
use Templately\Modules\TemplateCustomizer\REST\LogoGeneration;

class Module extends Module_Base {

	public function get_name(): string {
		return 'template-customizer';
	}

	public function get_dependencies(): array {
		// REST/LogoGeneration.php uses full-site-import's Utils/AIUtils/SignatureVerifier
		// during AI logo generation (spec 025).
		return [ 'full-site-import' ];
	}

	public function register_rest_routes(): void {
		LogoGeneration::get_instance()->register_routes();
	}
}
