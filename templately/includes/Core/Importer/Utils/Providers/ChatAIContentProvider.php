<?php

namespace Templately\Core\Importer\Utils\Providers;

use Templately\Core\Importer\Utils\AIContentResolver;
use Templately\Core\Importer\Utils\AIUtils;
use Templately\Utils\Helper;

/**
 * Built-in AI content provider for the CHAT-ID generation flow (Phase 2 thin
 * import — content generated on the backend and pulled by conversation uuid).
 *
 * Unlike the classic flow there is no per-page callback: the content lives on
 * the backend and must be pulled. This provider pulls the ready pages via
 * `v2/chatbot/generated/{chat}` and writes each to its `.ai.json` using the same
 * {@see AIUtils::save_template_to_file()} the classic callback uses — so the
 * wait/merge downstream is byte-for-byte the classic path.
 *
 * The wait now happens at the END (inside the Finalizer, through the resolver)
 * instead of up front: the import starts immediately and each page is pulled
 * on demand as finalize reaches it, mirroring the classic AI flow.
 *
 * A process is owned by this provider when its stored process data carries a
 * `chat_id` (set when the chat import registers its process). Registered on
 * {@see AIContentResolver::STAGE_HOOK}.
 */
class ChatAIContentProvider {

	/**
	 * Backend `App\Enums\ChatbotGeneratedReadiness::READY` — generation for the
	 * conversation has finished (PENDING = 0).
	 */
	const READINESS_READY = 1;

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
		$chat_id      = $process_data['chat_id'] ?? '';

		// Not a chat-sourced import — let another provider handle it.
		if ( empty( $chat_id ) ) {
			return $staged;
		}

		$session_id  = $context['session_id'] ?? '';
		$process_id  = $context['process_id'] ?? null;
		$ai_page_ids = $context['ai_page_ids'] ?? [];

		if ( empty( $process_id ) || empty( $session_id ) || empty( $ai_page_ids ) ) {
			return $staged;
		}

		// The api_key `chatbot_import_prepare` banked on the process data. It is
		// REQUIRED here: prepare runs as a REST call authenticated by the browser's
		// `X-Templately-Apikey` header (API\AIContent overrides _permission_check),
		// but the Finalizer runs in the admin-ajax/SSE import where no such header
		// exists and `Options::get('api_key')` can be empty or user-scoped to a
		// different user. Without it every pull here comes back 401 and the wait
		// spins to its timeout.
		$api_key = $process_data['api_key'] ?? '';

		self::pull_generated( $chat_id, $process_id, $session_id, $ai_page_ids, $api_key );

		return AIContentResolver::page_staged( $context ) ? true : $staged;
	}

	/**
	 * Run a callback with the Templately API Bearer forced to `$api_key`.
	 *
	 * `Helper::make_api_request()` reads `Options::get('api_key')` for the
	 * Authorization header, which is not reliably populated in the import
	 * context — so override it through the same `templately_api_request_params`
	 * filter the request already applies, and always unhook afterwards.
	 *
	 * @param string   $api_key  Key to authenticate with (no-op when empty).
	 * @param callable $callback Receives no arguments; its return value is returned.
	 * @return mixed
	 */
	private static function with_api_key( $api_key, callable $callback ) {
		if ( empty( $api_key ) ) {
			return $callback();
		}

		$override = function ( $args ) use ( $api_key ) {
			$args['headers']['Authorization'] = 'Bearer ' . $api_key;
			return $args;
		};

		add_filter( 'templately_api_request_params', $override, 999 );
		try {
			return $callback();
		} finally {
			remove_filter( 'templately_api_request_params', $override, 999 );
		}
	}

	/**
	 * Pull every currently-generated page for a conversation and write each to
	 * its `.ai.json` (plus explicit skip markers), recording credits consumed.
	 *
	 * Idempotent and safe to call on each finalize wait tick: re-writing an
	 * already-present page is harmless, and pages still generating simply have
	 * not appeared yet. This is the chat twin of
	 * {@see AIUtils::poll_for_template()}.
	 *
	 * @param string     $chat_id     Conversation uuid.
	 * @param string     $process_id  AI process id (keys processed-pages data).
	 * @param string     $session_id  Import session id.
	 * @param array      $ai_page_ids `type/sub_type => [content_id,...]` map.
	 * @return bool True when the pull succeeded (regardless of how many pages were ready).
	 */
	public static function pull_generated( $chat_id, $process_id, $session_id, $ai_page_ids, $api_key = '' ): bool {
		$response = self::with_api_key( $api_key, function () use ( $chat_id ) {
			return Helper::make_api_get_request( "v2/chatbot/generated/{$chat_id}", [], [ 'Accept' => 'application/json' ], 30 );
		} );

		if ( is_wp_error( $response ) ) {
			Helper::log( sprintf( 'chat_provider[%s] pull failed: %s', $chat_id, $response->get_error_message() ), 'ai-import', 'error' );
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code !== 200 ) {
			Helper::log( sprintf( 'chat_provider[%s] pull HTTP %d', $chat_id, $response_code ), 'ai-import', 'error' );
			return false;
		}

		$data      = json_decode( wp_remote_retrieve_body( $response ), true );
		$generated = ( is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ) ? $data['data'] : [];

		// Access gate — mirrors the chatbot-import-prepare endpoint. A free user
		// past their 7-day window must not have content pulled mid-import.
		if ( isset( $generated['can_import'] ) && ! $generated['can_import'] ) {
			Helper::log( sprintf( 'chat_provider[%s] can_import=false, skipping pull', $chat_id ), 'ai-import', 'error' );
			return false;
		}

		$templates = ( isset( $generated['templates'] ) && is_array( $generated['templates'] ) ) ? $generated['templates'] : [];
		$skipped   = ( isset( $generated['skipped_pages'] ) && is_array( $generated['skipped_pages'] ) ) ? array_map( 'strval', $generated['skipped_pages'] ) : [];

		// Write each newly-generated page to its .ai.json location for the runners.
		// Pages already on disk are skipped — this runs on every finalize tick and
		// re-serialising the whole bundle each time is pure waste.
		$written = 0;
		foreach ( $templates as $content_id => $template ) {
			if ( empty( $template ) ) {
				continue;
			}
			if ( self::page_file_exists( $session_id, $content_id, $ai_page_ids ) ) {
				continue;
			}
			// The runners read JSON strings; normalize arrays/objects to a string.
			$payload = is_string( $template ) ? $template : wp_json_encode( $template );
			AIUtils::save_template_to_file( $process_id, $session_id, $content_id, $payload, $ai_page_ids, false );
			$written++;
		}

		// Backend-skipped pages → explicit `{"isSkipped":true}` markers so finalize
		// falls back to the pack's default content instead of waiting forever.
		foreach ( $skipped as $skipped_id ) {
			if ( array_key_exists( $skipped_id, $templates ) || array_key_exists( (int) $skipped_id, $templates ) ) {
				continue;
			}
			if ( self::page_file_exists( $session_id, $skipped_id, $ai_page_ids ) ) {
				continue;
			}
			AIUtils::save_template_to_file( $process_id, $session_id, $skipped_id, '', $ai_page_ids, true );
			$written++;
		}

		$expected = AIUtils::flatten_ai_page_ids( $ai_page_ids );
		$missing  = array_values( array_filter( $expected, function ( $id ) use ( $templates, $skipped ) {
			return ! array_key_exists( $id, $templates )
				&& ! array_key_exists( (int) $id, $templates )
				&& ! in_array( (string) $id, $skipped, true );
		} ) );

		// Generation is FINISHED but some expected pages were never delivered and
		// were never listed in `skipped_pages` either — the backend silently did
		// not produce them (seen live: a pack's "Product Roadmap" page absent from
		// a `status: READY` bundle with `skipped_pages: []`).
		//
		// Nothing will ever arrive for these, so treat them as skipped and write
		// the marker now. Without this the Finalizer waits the full 420s on each
		// one before falling back to the pack's default content anyway — a
		// multi-minute stall with a frozen progress bar for an identical result.
		if ( ! empty( $missing ) && self::is_generation_finished( $generated ) ) {
			foreach ( $missing as $undelivered_id ) {
				if ( self::page_file_exists( $session_id, $undelivered_id, $ai_page_ids ) ) {
					continue;
				}
				AIUtils::save_template_to_file( $process_id, $session_id, $undelivered_id, '', $ai_page_ids, true );
				$written++;
			}
			Helper::log( sprintf(
				'chat_provider[%s] generation finished but %d page(s) never delivered (%s) — marked skipped, importing pack defaults',
				$chat_id, count( $missing ), implode( ',', $missing )
			), 'ai-import', 'error' );
			$missing = [];
		}

		// Record credits consumed ONLY once generation is genuinely complete.
		//
		// `credit_cost` is the pipeline's "generation finished, stop waiting"
		// signal: its mere presence makes handle_sse_wait_with_timeout() return
		// true immediately. The backend reports `credits_consumed` incrementally,
		// so writing it on the first pull would disable waiting for the entire
		// import and every still-generating page would fall back to pack defaults.
		$credit_cost = $generated['credits_consumed'] ?? ( $generated['credit_cost'] ?? 0 );
		if ( empty( $missing ) && $credit_cost > 0 ) {
			$processed_pages                               = get_option( 'templately_ai_processed_pages', [] );
			$processed_pages[ $process_id ]                = $processed_pages[ $process_id ] ?? [];
			$processed_pages[ $process_id ]['credit_cost'] = $credit_cost;
			update_option( 'templately_ai_processed_pages', $processed_pages, false );
		}

		Helper::log( sprintf(
			'chat_provider[%s] pull: wrote=%d ready=%d/%d missing=%d skipped=%d',
			$chat_id, $written, count( $expected ) - count( $missing ), count( $expected ), count( $missing ), count( $skipped )
		), 'ai-import', 'info' );

		return true;
	}

	/**
	 * Whether the backend considers generation for this conversation FINISHED.
	 *
	 * Mirrors the frontend's `GENERATED_READINESS_READY` (see
	 * AiContentSidebar/AIConversation.js — backend enum
	 * `App\Enums\ChatbotGeneratedReadiness`: PENDING = 0, READY = 1). Once READY,
	 * a page absent from both `templates` and `skipped_pages` is never coming.
	 *
	 * Deliberately strict: an unknown/missing `status` is treated as NOT finished,
	 * so an unrecognised payload makes us keep waiting rather than prematurely
	 * degrade pages to pack default content.
	 *
	 * @param array $generated The bundle's `data` payload.
	 * @return bool
	 */
	private static function is_generation_finished( array $generated ): bool {
		return isset( $generated['status'] ) && (int) $generated['status'] === self::READINESS_READY;
	}

	/**
	 * Whether a page's `.ai.json` is already on disk, so the pull can skip
	 * rewriting it. Thin wrapper over the resolver's staged check.
	 *
	 * @param string     $session_id
	 * @param string|int $content_id
	 * @param array      $ai_page_ids
	 * @return bool
	 */
	private static function page_file_exists( $session_id, $content_id, $ai_page_ids ): bool {
		return AIContentResolver::page_staged( [
			'session_id'  => $session_id,
			'content_id'  => $content_id,
			'ai_page_ids' => $ai_page_ids,
		] );
	}
}
