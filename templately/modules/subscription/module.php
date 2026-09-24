<?php
/**
 * Subscription module.
 *
 * "Subscription" in the cloud sidebar — the current plan and the comparison
 * table. Ported from `latest`'s `profile/Subscription.js`.
 *
 * DELIBERATELY OWNS NO ROUTE. The screen is read-only: plan changes,
 * cancellation and resumption all happen on templately.com, and it links out
 * for them. What it reads already exists — the plan comes from the stored user
 * record (`connect` / `profile/sync`) and the catalog from the public
 * `/templately/v1/pricing` endpoint template-browsing already serves. Purchases
 * go through `template-details`' `POST /checkout`, whose `subscription` branch
 * is admin-gated.
 *
 * So this module is JS-only: a route registration and a screen.
 *
 * @package Templately
 */

namespace Templately\Modules\Subscription;

use Templately\Core\Module_Base;

class Module extends Module_Base {

	public function get_name(): string {
		return 'subscription';
	}
}
