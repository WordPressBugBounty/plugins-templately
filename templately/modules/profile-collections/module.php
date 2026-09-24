<?php
/**
 * Profile-collections module (spec 029-profile-collections).
 *
 * Favourites/downloads/purchased-items listing pages. Relocated verbatim from
 * includes/API/Profile.php — behavior byte-identical (same 3 routes: download-
 * history, my-favourites, purchased-items). NOT the same class as
 * modules/auth/REST/Profile.php (profile/sync, profile/verified — a genuinely
 * different, pre-existing class; a stale includes/Core/Migrator.php reference
 * to the WRONG "Profile" class, calling ::sync() on THIS class where it never
 * existed, was fixed as part of this move — see the PRD's blockers log).
 *
 * SCOPE NOTE: the favourite TOGGLE endpoint (POST /items/favourite) stays in
 * modules/template-browsing/REST/Items.php (spec 017's own established
 * boundary — not moved). The favourites/downloads/purchasedItems REDUX state,
 * action creators, and sagas all live in modules/auth (profileReducer.js /
 * actions.js / profileSaga.js, spec 013) — too entangled with the rest of
 * profileReducer to split out, so this module owns only the REST + the view
 * components, cross-referencing auth's state/actions/sagas as-is. No
 * templately.routes registration and no webpack partial for the same reason
 * 028-my-cloud deferred its own: the /downloads, /favourites, /purchased-items
 * routes share one CloudsLayout parent wrapper with 028's /clouds route (also
 * CloudsLayout-wrapped) — TemplatelyRoutes.js keeps its inline route
 * definitions, importing Downloads/Favourites/PurchasedItems from this module
 * via the profile/ barrel's re-export.
 *
 * @package Templately
 */

namespace Templately\Modules\ProfileCollections;

use Templately\Core\Module_Base;
use Templately\Modules\ProfileCollections\REST\Profile;

class Module extends Module_Base {

	public function get_name(): string {
		return 'profile-collections';
	}

	public function register_rest_routes(): void {
		Profile::get_instance()->register_routes();
	}
}
