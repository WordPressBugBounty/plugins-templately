<?php
/**
 * AI-Studio module.
 *
 * "AI Studio" in the cloud sidebar — every site this account generated through
 * Templately AI, so a generation can be re-imported at any time instead of the
 * user having to keep its templately.com link, plus the credit balance and
 * usage history that sit alongside it.
 *
 * Ported from `latest`, where the endpoints lived as `includes/API/AiCredit.php`
 * and the screen as `profile/AiSites.js` + `profile/CreditsTab.js`.
 *
 * The LIST of generations is not here: it is
 * `ai-content/chatbot-conversations` in `modules/ai-fsi`, alongside the
 * single-conversation route it is the plural of, and this module reaches it by
 * URL rather than by importing that module.
 *
 * @package Templately
 */

namespace Templately\Modules\AiStudio;

use Templately\Core\Module_Base;
use Templately\Modules\AiStudio\REST\AiCredit;

class Module extends Module_Base {

	public function get_name(): string {
		return 'ai-studio';
	}

	/**
	 * NOTE: no `get_dependencies()` on ai-fsi or full-site-import.
	 *
	 * The screen's Import action dispatches `triggerFSIHandleClick` and mounts a
	 * headless `FullSiteImport`, which are JS-side imports resolved through the
	 * single first-party bundle graph — not a PHP boot-order edge. The endpoint
	 * it reads is reached by route string. Declaring a dependency here would
	 * assert an ordering constraint that does not exist, and this module boots
	 * fine with either of those absent.
	 */
	public function register_rest_routes(): void {
		AiCredit::get_instance()->register_routes();
	}
}
