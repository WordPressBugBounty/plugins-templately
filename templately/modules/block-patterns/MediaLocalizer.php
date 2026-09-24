<?php

namespace Templately\Modules\BlockPatterns;

use Templately\Utils\Base;
use Templately\Utils\Helper;

/**
 * Save-time media localization for natively-inserted patterns.
 *
 * A pattern inserted from the block inserter is pure client-side work — no
 * plugin code runs — so its Templately-hosted images would otherwise stay
 * hot-linked in user content forever. On save this scans for allow-listed
 * hosts ONLY (user media is never touched), sideloads what it finds, and
 * rewrites the content to the local copies.
 *
 * Dedup is PERSISTENT, keyed by the size-suffix-normalized source URL in
 * `_templately_source_url` attachment meta: the FSI import path dedups only
 * within a single request (a static array), so the same pattern inserted on
 * five posts would otherwise import the same hero image five times
 * (spec 051 FR-008; research D6/§6.5).
 */
class MediaLocalizer extends Base {

	const EVENT_LOCALIZE_REST = 'templately_block_patterns_localize_rest';
	const SOURCE_URL_META     = '_templately_source_url';
	const DEFAULT_BUDGET      = 10;

	const IMAGE_URL_PATTERN = '/(https?:\/\/[^\s<>"\']+\.(jpg|jpeg|gif|png|svg|webp|ico|tiff|tif)(?:\?[^\s<>"\']*)?)/i';

	/**
	 * Guard against re-entry: this class calls wp_update_post(), which fires
	 * wp_after_insert_post again.
	 *
	 * @var bool
	 */
	private $localizing = false;

	/**
	 * @param int          $post_id
	 * @param \WP_Post|null $post
	 */
	public function maybe_localize( $post_id, $post = null ): void {
		if ( $this->localizing ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type ) {
			return;
		}

		$this->localize( $post, $this->budget() );
	}

	/**
	 * Deferred remainder pass (cron): same work, no budget.
	 */
	public function localize_deferred( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post ) {
			$this->localize( $post, PHP_INT_MAX );
		}
	}

	/**
	 * Import the media referenced by a content string and return a
	 * remote-URL => local-URL map.
	 *
	 * Deliberately separate from the content fetch: holding the pattern back
	 * while up to ten images download makes insertion feel broken. The editor
	 * shows the design immediately with its demo URLs, then swaps them for local
	 * ones once this returns.
	 *
	 * @return array<string, string> Keyed by the exact URL as it appears in the markup.
	 */
	public function build_url_map( string $content, int $post_id = 0 ): array {
		$hosts = $this->allowed_hosts();
		if ( empty( $hosts ) || '' === $content ) {
			return [];
		}

		$map    = [];
		$budget = $this->budget();
		$done   = 0;

		foreach ( $this->find_localizable_urls( $content, $hosts ) as $url ) {
			if ( $done >= $budget ) {
				break;
			}
			$done++;

			$attachment_id = $this->resolve_attachment( $url, $post_id );
			if ( ! $attachment_id ) {
				continue;
			}

			$local_url = wp_get_attachment_url( $attachment_id );
			if ( ! $local_url ) {
				continue;
			}

			// Every size-suffixed variant in the markup maps to the same local file.
			foreach ( $this->variants_in( $content, $url ) as $variant ) {
				$map[ $variant ] = $local_url;
			}
		}

		return $map;
	}

	/**
	 * Localize a content string in one pass and hand it back.
	 *
	 * Kept for callers that genuinely want to block until the media is local
	 * (the save-time path); the INSERT path uses build_url_map instead so the
	 * design never waits on downloads.
	 */
	public function localize_content( string $content, int $post_id = 0 ): string {
		$hosts = $this->allowed_hosts();
		if ( empty( $hosts ) || '' === $content ) {
			return $content;
		}

		$urls = $this->find_localizable_urls( $content, $hosts );
		if ( empty( $urls ) ) {
			return $content;
		}

		$budget    = $this->budget();
		$processed = 0;

		foreach ( $urls as $url ) {
			if ( $processed >= $budget ) {
				break;
			}
			$processed++;

			$attachment_id = $this->resolve_attachment( $url, $post_id );
			if ( ! $attachment_id ) {
				continue;
			}

			$local_url = wp_get_attachment_url( $attachment_id );
			if ( ! $local_url ) {
				continue;
			}

			foreach ( $this->variants_in( $content, $url ) as $variant ) {
				$content = str_replace( $variant, $local_url, $content );
			}
		}

		return $content;
	}

	private function localize( \WP_Post $post, int $budget ): void {
		$hosts = $this->allowed_hosts();
		if ( empty( $hosts ) ) {
			return; // localizer disabled
		}

		$content = (string) $post->post_content;
		$urls    = $this->find_localizable_urls( $content, $hosts );
		if ( empty( $urls ) ) {
			return;
		}

		$processed = 0;
		$replaced  = false;
		foreach ( $urls as $url ) {
			if ( $processed >= $budget ) {
				// Never block or fail the user's save — finish the rest later.
				$this->schedule_remainder( $post->ID );
				break;
			}

			$attachment_id = $this->resolve_attachment( $url, $post->ID );
			$processed++;

			if ( ! $attachment_id ) {
				continue;
			}

			$local_url = wp_get_attachment_url( $attachment_id );
			if ( ! $local_url ) {
				continue;
			}

			// Replace the base URL and every size-suffixed variant of it.
			foreach ( $this->variants_in( $content, $url ) as $variant ) {
				$content  = str_replace( $variant, $local_url, $content );
				$replaced = true;
			}
		}

		if ( ! $replaced ) {
			return;
		}

		$this->localizing = true;
		wp_update_post( [
			'ID'           => $post->ID,
			'post_content' => wp_slash( $content ),
		] );
		$this->localizing = false;
	}

	/**
	 * Distinct, size-suffix-normalized source URLs on allow-listed hosts.
	 *
	 * @return string[]
	 */
	private function find_localizable_urls( string $content, array $hosts ): array {
		preg_match_all( self::IMAGE_URL_PATTERN, $content, $matches );

		$urls = [];
		foreach ( (array) $matches[0] as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( ! $host || ! $this->host_allowed( (string) $host, $hosts ) ) {
				continue;
			}
			$base = $this->normalize_url( $url );
			if ( ! in_array( $base, $urls, true ) ) {
				$urls[] = $base;
			}
		}

		return $urls;
	}

	/**
	 * Every literal occurrence in the content that maps to this base URL —
	 * the base itself plus any `-300x200`-style size variants.
	 *
	 * @return string[]
	 */
	private function variants_in( string $content, string $base_url ): array {
		preg_match_all( self::IMAGE_URL_PATTERN, $content, $matches );

		$variants = [];
		foreach ( (array) $matches[0] as $url ) {
			if ( $this->normalize_url( $url ) === $base_url && ! in_array( $url, $variants, true ) ) {
				$variants[] = $url;
			}
		}

		return $variants;
	}

	private function normalize_url( string $url ): string {
		$url = strtok( $url, '?' );

		return preg_replace( '/-\d+x\d+(?=\.[a-zA-Z]+$)/', '', $url );
	}

	/**
	 * Existing attachment for this source URL, or a freshly sideloaded one.
	 */
	private function resolve_attachment( string $source_url, int $post_id ): ?int {
		$existing = $this->find_existing( $source_url );
		if ( $existing ) {
			return $existing;
		}

		/**
		 * Short-circuit the download+sideload step. Returning an attachment id
		 * (or null) skips the built-in implementation.
		 *
		 * @param int|null $pre
		 * @param string   $source_url
		 * @param int      $post_id
		 */
		$pre = apply_filters( 'templately_block_patterns_sideload', null, $source_url, $post_id );
		$attachment_id = null === $pre ? $this->sideload( $source_url, $post_id ) : $pre;

		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			Helper::log( "block-patterns: sideload failed for {$source_url}" );

			return null;
		}

		update_post_meta( (int) $attachment_id, self::SOURCE_URL_META, $source_url );

		return (int) $attachment_id;
	}

	private function find_existing( string $source_url ): ?int {
		$found = get_posts( [
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'meta_key'         => self::SOURCE_URL_META,
			'meta_value'       => $source_url,
			'suppress_filters' => false,
		] );

		return ! empty( $found ) ? (int) $found[0] : null;
	}

	/**
	 * @return int|\WP_Error
	 */
	private function sideload( string $source_url, int $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $source_url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$file_array = [
			'name'     => basename( strtok( $source_url, '?' ) ),
			'tmp_name' => $tmp,
		];

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) && file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		return $attachment_id;
	}

	/**
	 * Remove media this module imported for a post that is being deleted.
	 *
	 * Insert-time import means a user who tries a pattern and then discards the
	 * draft would otherwise leave its images behind in the media library. Only
	 * attachments this module created (they carry the source-URL meta), only
	 * those parented to the post, and only when no OTHER post still references
	 * them — a shared image stays.
	 */
	public function cleanup_for_post( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! $post_id || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$attachments = get_posts( [
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'post_parent'      => $post_id,
			'numberposts'      => -1,
			'fields'           => 'ids',
			'meta_key'         => self::SOURCE_URL_META,
			'suppress_filters' => false,
		] );

		foreach ( (array) $attachments as $attachment_id ) {
			$url = wp_get_attachment_url( (int) $attachment_id );
			if ( $url && $this->url_used_elsewhere( $url, $post_id ) ) {
				continue;
			}
			wp_delete_attachment( (int) $attachment_id, true );
		}
	}

	/**
	 * Whether any post other than $exclude_id still references this URL.
	 */
	private function url_used_elsewhere( string $url, int $exclude_id ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_content LIKE %s
				   AND ID != %d
				   AND post_type NOT IN ( 'revision', 'attachment' )
				   AND post_status != 'trash'
				 LIMIT 1",
				'%' . $wpdb->esc_like( $url ) . '%',
				$exclude_id
			)
		);

		return ! empty( $found );
	}

	private function schedule_remainder( int $post_id ): void {
		if ( ! wp_next_scheduled( self::EVENT_LOCALIZE_REST, [ $post_id ] ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::EVENT_LOCALIZE_REST, [ $post_id ] );
		}
	}

	private function budget(): int {
		return (int) apply_filters( 'templately_block_patterns_media_budget', self::DEFAULT_BUDGET );
	}

	/**
	 * Host suffixes whose images this module may import. An empty list disables
	 * the localizer entirely.
	 *
	 * Suffixes, not exact hosts: real pattern content serves its media from
	 * `demo.assets.templately.com`, and an earlier hardcoded list of guessed
	 * hostnames (app./cdn.) matched nothing, so localization silently did
	 * nothing at all. Matching the domain covers whichever subdomain the cloud
	 * uses without another round of guessing.
	 *
	 * @return string[]
	 */
	private function allowed_hosts(): array {
		$hosts = apply_filters( 'templately_block_patterns_media_hosts', [
			'templately.com',
			'templately.dev',
		] );

		return array_map( 'strtolower', array_filter( (array) $hosts ) );
	}

	/**
	 * Whether a host is covered by the allow-list — an exact match, or a
	 * subdomain of a listed domain. Deliberately anchored on a leading dot so
	 * `nottemplately.com` cannot match `templately.com`.
	 */
	private function host_allowed( string $host, array $hosts ): bool {
		$host = strtolower( $host );

		foreach ( $hosts as $allowed ) {
			if ( $host === $allowed || substr( $host, -strlen( '.' . $allowed ) ) === '.' . $allowed ) {
				return true;
			}
		}

		return false;
	}
}
