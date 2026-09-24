<?php
/**
 * Library tour module (spec 036-library-tour).
 *
 * Relocated: includes/API/Tour.php (the REST controller — 3 endpoints:
 * GET tour/status, POST tour/complete, POST tour/reset) +
 * react-src/app/components/new-components/tour/ (9-file React component
 * tree: LibraryTour, DetailTour, TourTooltip, TourContext, TourMenuItem,
 * TourShortcutListener, useTourShortcut, tourSteps, index barrel).
 * Namespace: Templately\API\Tour -> Templately\Modules\LibraryTour\REST\Tour.
 *
 * No boot-ordering fix needed: Tour::get_instance() was eagerly instantiated
 * in Plugin::apis() (during plugins_loaded) purely to self-register into the
 * platform-adapter Platform_Registry::add($this, 'API') registry — a registration that
 * Plugin::register_routes() already skips for any Templately\Modules\* class
 * (see that method's own skip-comment). The eager call was simply removed;
 * this module's register_rest_routes() wires the real route registration on
 * rest_api_init via Module_Base::boot_rest_routes(), the same lazy pattern
 * every module-owned REST class uses (013-035) — no autoloader-timing hazard
 * for a class only ever resolved at rest_api_init.
 *
 * JS ships inside the main bundle (LibraryTour/DetailTour/TourMenuItem/
 * TourProvider/TourShortcutListener are all statically imported from
 * App.js, NewItemDetails.js, UserNavigation.js, and
 * modules/template-browsing's TemplatesTypeLayout.js — not a lazy module
 * route), matching the established precedent from specs 034/035 for
 * main-bundle features: no webpack.config.partial.js, no attachReducer/
 * runSaga (this feature has no Redux state at all — TourContext is a plain
 * React context).
 *
 * @package Templately
 */

namespace Templately\Modules\LibraryTour;

use Templately\Core\Module_Base;
use Templately\Modules\LibraryTour\REST\Tour;

class Module extends Module_Base {

	public function get_name(): string {
		return 'library-tour';
	}

	public function register_rest_routes(): void {
		Tour::get_instance()->register_routes();
	}
}
