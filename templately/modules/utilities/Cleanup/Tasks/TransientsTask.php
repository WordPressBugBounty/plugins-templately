<?php
/**
 * Purge the plugin's own expired cache entries.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup\Tasks;

use Templately\Modules\Utilities\Cleanup\CleanupTask;
use Templately\Modules\Utilities\Cleanup\Context;
use Templately\Modules\Utilities\Cleanup\RetentionKind;
use Templately\Modules\Utilities\Cleanup\Tasks\Support\Transients;
use Templately\Modules\Utilities\Cleanup\TaskResult;

/**
 * Shipped sites had NO transient purge at all before this: the cleaner existed
 * only in `modules/developer/`, which `.distignore` strips from the zip.
 *
 * Two modes, and the split matters:
 *
 *  - SCHEDULED removes only entries WordPress already considers expired. Safe
 *    to run unattended because nothing live is touched.
 *  - MANUAL (the developer panel's "delete all") removes unexpired ones too.
 *    Deliberately not schedulable: the cached pattern library is a transient,
 *    and dropping it unexpired costs real cloud requests to rebuild.
 *
 * INTRINSIC retention: each entry carries the expiry its writer chose. The
 * shared age window must not apply — a 7-day rule would delete cache the plugin
 * still considers live.
 */
class TransientsTask implements CleanupTask {

	public function descriptor(): array {
		return [
			'id'             => 'plugin-transients',
			'label'          => __( 'Expired cached data', 'templately' ),
			'group'          => 'cache',
			'scope'          => 'records',
			'retention_kind' => RetentionKind::INTRINSIC,
			'destructive'    => true,
			'schedulable'    => true,
		];
	}

	public function estimate( Context $context ): TaskResult {
		return TaskResult::empty()->add( count( $this->expired_keys() ), 0 );
	}

	public function run( Context $context ): TaskResult {
		$expired = $this->expired_keys();

		if ( $context->is_dry_run() ) {
			return TaskResult::empty()->add( count( $expired ), 0 );
		}

		$result = TaskResult::empty();

		foreach ( $expired as $key ) {
			if ( delete_transient( $key ) ) {
				$result->add( 1, 0 );
			}
		}

		return $result;
	}

	/**
	 * Keys whose stored timeout has already passed.
	 *
	 * Scoped to the plugin's own `_templately_` entries — reclaiming another
	 * plugin's cache is explicitly out of scope, and WordPress's own
	 * `delete_expired_transients()` is site-wide.
	 *
	 * @return string[]
	 */
	private function expired_keys(): array {
		$expired = [];
		$now     = time();

		foreach ( Transients::get_templately_transients() as $row ) {
			$key = Transients::option_name_to_transient_key( $row->option_name );

			$timeout = get_option( '_transient_timeout_' . $key, false );
			if ( false === $timeout ) {
				// No timeout row = never expires. Not ours to reclaim on a schedule.
				continue;
			}

			if ( (int) $timeout < $now ) {
				$expired[] = $key;
			}
		}

		return $expired;
	}
}
