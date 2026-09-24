<?php
/**
 * Image-replace module (spec 023-image-replace-module).
 *
 * REST: image search (`GET /templately/v1/ai-content/images`) — relocated from
 * includes/API/AIContent.php's search_images()/register_routes(), a self-contained
 * method with no shared state and no custom permission override (fell through to the
 * base API::_permission_check() default there too). The upload tab additionally uses
 * the standard WordPress Media Library (`wp/v2/media`) REST endpoint via
 * `wp_enqueue_media()`, already called globally for all Templately admin screens by
 * the admin infrastructure (spec 011) — no PHP registration needed for that part.
 *
 * The JS (ImageReplacePanel/ + LightBoxForAiConversation.js — relocated verbatim from
 * react-src/app/components/fsi/sidebar/ImageReplacePanel/ and react-src/app/components/
 * core/, both had zero consumers outside this feature) has no independent mount point —
 * it's deep-imported directly by its callers (the not-yet-extracted FSI sidebar's
 * AiConversationPanel.tsx, the SDK surface in react-src/sdk/, and the root
 * templately.js bundle for window.TemplatelyImageReplacePanelIsolated), matching spec
 * 019's "no webpack partial" pattern.
 *
 * @package Templately
 */

namespace Templately\Modules\ImageReplace;

use Templately\Core\Module_Base;
use Templately\Modules\ImageReplace\REST\ImageSearch;

class Module extends Module_Base {

	public function get_name(): string {
		return 'image-replace';
	}

	public function register_rest_routes(): void {
		ImageSearch::get_instance()->register_routes();
	}
}
