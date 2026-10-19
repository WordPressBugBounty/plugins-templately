<?php
/**
 * Subscription module.
 *
 * "Subscription" in the cloud sidebar, the current plan, the Pro plugins the
 * plan includes, and the comparison table. Ported from `latest`'s
 * `profile/Subscription.js`.
 *
 * The screen is read-only about the SUBSCRIPTION: plan changes, cancellation
 * and resumption all happen on templately.com, and it links out for them. The
 * plan comes from the stored user record (`connect` / `profile/sync`), the
 * catalog from the public `/templately/v1/pricing` endpoint `modules/auth`
 * serves, and purchases go through `template-details`' `POST /checkout`.
 *
 * It owns ONE route of its own (2026-10-15): `GET subscription/pro-plugin-download`,
 * which resolves the download link for the Pro plugin a bundle subscription
 * includes. That lives here rather than on the client because the download
 * service wants the site's Templately credential in the request URL, and the
 * browser must never see it. The user downloads and uploads the plugin
 * themselves; Templately never installs it (WordPress.org plugin guideline 8).
 *
 * @package Templately
 */

namespace Templately\Modules\Subscription;

use Templately\Core\Module_Base;
use Templately\Modules\Subscription\REST\ProPluginDownload;

class Module extends Module_Base {

	public function get_name(): string {
		return 'subscription';
	}

	public function register_rest_routes(): void {
		ProPluginDownload::get_instance()->register_routes();
	}
}
