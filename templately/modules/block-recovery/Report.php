<?php

namespace Templately\Modules\BlockRecovery;

use Templately\Utils\Helper;

/**
 * What the browser pass could not repair, aggregated into something a user can act on.
 *
 * Skips are not failures of the pass — they are the pass refusing to destroy data. A block is
 * skipped when the installed plugin does not declare an attribute the stored markup carries
 * (`parse` would drop it, and rebuilding would write it out of existence permanently). The
 * useful message is therefore not "we could not fix this" but "this plugin is behind the
 * version this pack was built with; update it and these blocks fix themselves".
 */
class Report {

	const OPTION = 'templately_block_rebuild_report';

	/** Which trigger produced an outcome (060 FR-013). Rows from before 060 read as the batch. */
	const TRIGGER_BATCH   = 'batch';
	const TRIGGER_ON_OPEN = 'on-open';

	/** Reports older than this are dropped, matching the queue's own retention. */
	const MAX_AGE = 7 * DAY_IN_SECONDS;

	/**
	 * Fold one post's result into the stored report.
	 *
	 * @param int    $post_id   Post that was visited.
	 * @param string $status    'clean' | 'rebuilt' | 'skipped' | 'failed'.
	 * @param int    $rebuilt   Number of blocks regenerated.
	 * @param array  $skipped   `[['block' => 'name', 'droppedAttrs' => ['x']], …]`
	 * @param int    $preserved Blocks rebuilt whose undeclared attributes were merged back.
	 * @param int    $restored  Individual attributes merged back.
	 * @param string $trigger   Which trigger produced this: 'batch' | 'on-open'. Unknown → 'batch'.
	 * @param int    $duration  Wall time the trigger spent on this post, in ms (0 = not measured).
	 */
	public static function record( int $post_id, string $status, int $rebuilt, array $skipped, int $preserved = 0, int $restored = 0, string $trigger = self::TRIGGER_BATCH, int $duration = 0 ) {
		$report = self::get();

		if ( ! in_array( $trigger, [ self::TRIGGER_BATCH, self::TRIGGER_ON_OPEN ], true ) ) {
			$trigger = self::TRIGGER_BATCH;
		}
		$duration = max( 0, $duration );

		$report['created_at']        = $report['created_at'] ?? time();
		$report['posts'][ $post_id ] = [
			'status'     => $status,
			'rebuilt'    => $rebuilt,
			'skipped'    => count( $skipped ),
			// Recorded so a summary rendered from the STORED report (a remount, a later visit)
			// says the same thing as the one rendered from the live run.
			'preserved'  => $preserved,
			'restored'   => $restored,
			'trigger'    => $trigger,
			'durationMs' => $duration,
		];

		$report['totals']['rebuilt']   = ( $report['totals']['rebuilt'] ?? 0 ) + $rebuilt;
		$report['totals']['skipped']   = ( $report['totals']['skipped'] ?? 0 ) + count( $skipped );
		$report['totals']['preserved'] = ( $report['totals']['preserved'] ?? 0 ) + $preserved;
		$report['totals']['restored']  = ( $report['totals']['restored'] ?? 0 ) + $restored;

		$by_trigger = $report['totals']['byTrigger'][ $trigger ] ?? [ 'posts' => 0, 'durationMs' => 0 ];
		$by_trigger['posts']++;
		$by_trigger['durationMs'] += $duration;
		$report['totals']['byTrigger'][ $trigger ] = $by_trigger;

		foreach ( $skipped as $entry ) {
			$block = isset( $entry['block'] ) ? (string) $entry['block'] : '';
			$slug  = self::plugin_slug_for_block( $block );

			if ( '' === $slug ) {
				continue;
			}

			$bucket = $report['plugins'][ $slug ] ?? [
				'slug'               => $slug,
				'blocks'             => 0,
				'unknownAttributes'  => [],
				'postIds'            => [],
			];

			$bucket['blocks']++;

			foreach ( (array) ( $entry['droppedAttrs'] ?? [] ) as $attribute ) {
				$attribute = (string) $attribute;
				if ( '' !== $attribute && ! in_array( $attribute, $bucket['unknownAttributes'], true ) ) {
					$bucket['unknownAttributes'][] = $attribute;
				}
			}

			if ( ! in_array( $post_id, $bucket['postIds'], true ) ) {
				$bucket['postIds'][] = $post_id;
			}

			$report['plugins'][ $slug ] = $bucket;
		}

		update_option( self::OPTION, $report, false );

		if ( ! empty( $skipped ) ) {
			Helper::log(
				[
					'context' => 'block_rebuild_skipped',
					'post_id' => $post_id,
					'skipped' => $skipped,
				],
				'fsi_event'
			);
		}
	}

	/**
	 * @return array{created_at?:int,posts?:array,totals?:array,plugins?:array}
	 */
	public static function get(): array {
		$report = get_option( self::OPTION, [] );

		if ( ! is_array( $report ) ) {
			return [];
		}

		if ( ! empty( $report['created_at'] ) && ( time() - (int) $report['created_at'] ) > self::MAX_AGE ) {
			self::clear();

			return [];
		}

		return $report;
	}

	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Map a block name to the plugin the user has to update.
	 *
	 * Block namespaces are not plugin slugs in general, so only the ones we can state with
	 * confidence are mapped; anything else is reported by its namespace rather than guessed at,
	 * because telling someone to update the wrong plugin is worse than naming none.
	 */
	private static function plugin_slug_for_block( string $block_name ): string {
		if ( '' === $block_name || false === strpos( $block_name, '/' ) ) {
			return '';
		}

		list( $namespace ) = explode( '/', $block_name, 2 );

		$known = [
			'essential-blocks' => 'essential-blocks',
			'core'             => '',
		];

		return array_key_exists( $namespace, $known ) ? $known[ $namespace ] : $namespace;
	}
}
