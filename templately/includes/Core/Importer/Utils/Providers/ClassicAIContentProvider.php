<?php

namespace Templately\Core\Importer\Utils\Providers;

use Templately\Core\Importer\Utils\AIContentResolver;
use Templately\Core\Importer\Utils\AIUtils;
use Templately\Core\Importer\Utils\SessionData;

/**
 * Built-in AI content provider for the CLASSIC generation flow (business-details
 * form → `ai-content/modify-content` → per-page `ai-update` callback).
 *
 * On remote sites the backend POSTs each page to the callback, which writes the
 * `.ai.json` directly, so nothing needs pulling here. On local sites (which the
 * backend cannot call back into) this provider pulls the ready pages via
 * {@see AIUtils::poll_for_template()} — the same behaviour that previously lived
 * inline in `AIUtils::handle_sse_wait_with_timeout()`, now expressed as a
 * registered source so both AI-content versions hook the resolver symmetrically.
 *
 * Registered on {@see AIContentResolver::STAGE_HOOK}.
 */
class ClassicAIContentProvider {

	/**
	 * @param bool  $staged  True if a previous provider already produced the file.
	 * @param array $context Resolver context (see AIContentResolver::ensure_page_ready).
	 * @return bool
	 */
	public static function stage( $staged, array $context ) {
		if ( $staged === true ) {
			return true;
		}

		$process_data = $context['process_data'] ?? [];

		// Chat-sourced imports are owned by ChatAIContentProvider — skip.
		if ( ! empty( $process_data['chat_id'] ) ) {
			return $staged;
		}

		$session_id  = $context['session_id'] ?? '';
		$process_id  = $context['process_id'] ?? null;
		$ai_page_ids = $context['ai_page_ids'] ?? [];
		$content_id  = $context['content_id'] ?? null;

		if ( empty( $process_id ) || empty( $session_id ) || empty( $ai_page_ids ) || $content_id === null || $content_id === '' ) {
			return $staged;
		}

		// Remote sites receive content through the ai-update callback; only local
		// sites need an active pull (the backend cannot reach them).
		$session_data = SessionData::get_data( $session_id );
		if ( empty( $session_data['isLocalSite'] ) ) {
			return $staged;
		}

		AIUtils::poll_for_template( $process_id, $session_id, $ai_page_ids );

		return AIContentResolver::page_staged( $context ) ? true : $staged;
	}
}
