<?php
/**
 * AI-FSI module (spec 026-ai-fsi).
 *
 * AI content generation workflow + conversational UI. PHP: AIContent.php's remaining
 * `/ai-content/*` routes (modify-content, ai-update, ai-update-preview, generate-tagline,
 * chatbot-conversation, attachments — `search_images`/`/images` already extracted to
 * modules/image-replace in spec 023). Relocated verbatim from includes/API/AIContent.php
 * — behavior byte-identical (same routes, params, HMAC/header-auth permission logic).
 *
 * @package Templately
 */

namespace Templately\Modules\AiFsi;

use Templately\Core\Module_Base;
use Templately\Modules\AiFsi\REST\AIContent;

class Module extends Module_Base {

	public function get_name(): string {
		return 'ai-fsi';
	}

	public function get_dependencies(): array {
		// REST/AIContent.php uses full-site-import's Utils/AIUtils/SessionData/
		// SignatureVerifier/WXR_Parser at request time (spec 025). (image-replace stays
		// baselined — that's a JS-only build-time import, PHP boot order has no bearing.)
		return [ 'full-site-import' ];
	}

	public function register_rest_routes(): void {
		AIContent::get_instance()->register_routes();
	}
}
