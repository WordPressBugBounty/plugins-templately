<?php

namespace Templately\Core\Importer\Utils;

use Templately\Core\Importer\Utils\Providers\ChatAIContentProvider;
use Templately\Core\Importer\Utils\Providers\ClassicAIContentProvider;

/**
 * Central, source-agnostic seam for making an AI-generated page's `.ai.json`
 * available at finalize time.
 *
 * The Finalizer no longer knows *how* a page's AI content arrives. It calls
 * {@see AIContentResolver::ensure_page_ready()} for every AI page; each AI
 * content "source" (the classic per-page `ai-update` callback flow, the chat-id
 * pull flow, and any future source) registers a provider on the
 * `templately_ai_stage_page` filter. A provider that owns the current process
 * pulls/writes the missing `.ai.json` and returns `true`; otherwise the shared
 * SSE-wait/timeout runs — identical behaviour for every source.
 *
 * Extending in the future is additive and needs no core edit:
 *   add_filter( AIContentResolver::STAGE_HOOK, [ MyProvider::class, 'stage' ], 10, 2 );
 * (optionally from a `templately_register_ai_content_providers` action listener).
 */
class AIContentResolver {

	/**
	 * Filter run for each AI page that still needs its `.ai.json`.
	 *
	 * @param bool  $staged  Whether a provider has already produced the file.
	 * @param array $context See {@see ensure_page_ready()} for the shape.
	 */
	const STAGE_HOOK = 'templately_ai_stage_page';

	/**
	 * Action fired once, right before the first stage dispatch, so external
	 * modules can register additional providers without touching core.
	 */
	const REGISTER_HOOK = 'templately_register_ai_content_providers';

	/**
	 * @var bool Guards one-time registration of the built-in providers.
	 */
	private static $booted = false;

	/**
	 * Register the built-in AI content providers exactly once, then let external
	 * code add its own. Both shipped AI-content versions hook in here.
	 *
	 * @return void
	 */
	public static function boot_default_providers() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_filter( self::STAGE_HOOK, [ ClassicAIContentProvider::class, 'stage' ], 10, 2 );
		add_filter( self::STAGE_HOOK, [ ChatAIContentProvider::class, 'stage' ], 10, 2 );

		do_action( self::REGISTER_HOOK );
	}

	/**
	 * Ensure the `.ai.json` for `$context['content_id']` is on disk, pulling it
	 * via whichever source owns this process, then (if still absent) SSE-waiting
	 * with timeout.
	 *
	 * @param array $context {
	 *   @type string       $session_id          Import session id.
	 *   @type string|null  $process_id          AI process id (keys process/progress data).
	 *   @type string|int   $content_id          The AI page id being finalized.
	 *   @type array        $ai_page_ids         `type/sub_type => [content_id,...]` map.
	 *   @type array        $updated_ids         `templately_ai_processed_pages[process_id]` snapshot.
	 *   @type callable     $sse_callback        Callback used to emit the SSE `wait` message.
	 *   @type array        $additional_sse_data Extra fields merged into the SSE `wait` payload.
	 *   @type string       $progress_id         Progress bucket key (default `ai_content_time`).
	 *   @type int          $timeout             Wait timeout in seconds (default 420).
	 * }
	 * @return bool True to continue finalizing the page (file ready or timed out).
	 *              Note: the shared wait handler may `exit()` to stream a `wait`.
	 */
	public static function ensure_page_ready( array $context ): bool {
		self::boot_default_providers();

		$process_id              = $context['process_id'] ?? null;
		$process_data            = ! empty( $process_id ) ? AIUtils::get_ai_process_data_by_process_id( $process_id ) : null;
		$context['process_data'] = is_array( $process_data ) ? $process_data : [];

		// Give the owning source a chance to stage (pull + write) the page.
		$staged = apply_filters( self::STAGE_HOOK, false, $context );
		if ( $staged === true ) {
			return true;
		}

		// Re-read the progress counters. The caller snapshots `updated_ids` BEFORE
		// calling us (Finalizer.php), but a provider may have just written several
		// pages — using the stale snapshot would under-report progress to the wait.
		if ( ! empty( $process_id ) ) {
			$processed_pages        = get_option( 'templately_ai_processed_pages', [] );
			$context['updated_ids'] = ( isset( $processed_pages[ $process_id ] ) && is_array( $processed_pages[ $process_id ] ) )
				? $processed_pages[ $process_id ]
				: ( $context['updated_ids'] ?? [] );
		}

		// No source produced the page yet → the shared SSE-wait/timeout, which
		// behaves identically regardless of where the content ultimately comes
		// from. This can `exit()` after emitting a `wait` message.
		return AIUtils::handle_sse_wait_with_timeout(
			$context['session_id'],
			$context['progress_id'] ?? 'ai_content_time',
			$context['updated_ids'] ?? [],
			AIUtils::flatten_ai_page_ids( $context['ai_page_ids'] ?? [] ),
			$context['sse_callback'],
			$context['additional_sse_data'] ?? [],
			$context['content_id'] ?? null,
			$context['timeout'] ?? 420
		);
	}

	/**
	 * Flatten the `type/sub_type => [content_id,...]` map into a single list of
	 * page ids.
	 *
	 * @deprecated Use {@see AIUtils::flatten_ai_page_ids()} — kept as a thin
	 *             delegate so existing provider/extension code keeps working.
	 *
	 * @param mixed $ai_page_ids Nested map, JSON string, or an already-flat list.
	 * @return array Flat list of content-id strings.
	 */
	public static function flatten_page_ids( $ai_page_ids ): array {
		return AIUtils::flatten_ai_page_ids( $ai_page_ids );
	}

	/**
	 * Whether the `.ai.json` for the context's page now exists on disk. Providers
	 * call this after a pull attempt to report success back to the resolver.
	 *
	 * @param array $context The same context passed to {@see ensure_page_ready()}.
	 * @return bool
	 */
	public static function page_staged( array $context ): bool {
		$session_id  = $context['session_id'] ?? '';
		$content_id  = $context['content_id'] ?? null;
		$ai_page_ids = AIUtils::normalize_ai_page_ids( $context['ai_page_ids'] ?? [] );

		if ( empty( $session_id ) || $content_id === null || $content_id === '' || empty( $ai_page_ids ) ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		$tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'templately' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $session_id . DIRECTORY_SEPARATOR;

		foreach ( $ai_page_ids as $key => $ids ) {
			if ( in_array( (string) $content_id, $ids, true ) ) {
				$sub_path  = str_replace( '/', DIRECTORY_SEPARATOR, (string) $key );
				$file_path = $tmp_dir . $sub_path . DIRECTORY_SEPARATOR . $content_id . '.ai.json';
				if ( file_exists( $file_path ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
