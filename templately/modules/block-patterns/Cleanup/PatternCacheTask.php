<?php
/**
 * Clear the cached pattern library on demand.
 *
 * @package Templately
 */

namespace Templately\Modules\BlockPatterns\Cleanup;

use Templately\Modules\BlockPatterns\PatternSync;
use Templately\Modules\Utilities\Cleanup\CleanupTask;
use Templately\Modules\Utilities\Cleanup\Context;
use Templately\Modules\Utilities\Cleanup\Remover;
use Templately\Modules\Utilities\Cleanup\RetentionKind;
use Templately\Modules\Utilities\Cleanup\TaskRegistry;
use Templately\Modules\Utilities\Cleanup\TaskResult;
use WP_Error;

/**
 * MANUAL ONLY — `schedulable` is false, and that is the whole point.
 *
 * This module already prunes its own orphaned content files at sync time, and
 * the cache is keyed by plan, so there is no leak here to fix on a schedule.
 * What this adds is an on-demand "rebuild the library" button for the Utilities
 * tab. Running it unattended would be actively harmful: every cleared pattern
 * is re-fetched from the cloud, so a nightly sweep would turn a working cache
 * into a nightly download.
 */
class PatternCacheTask implements CleanupTask {

	public static function register(): void {
		add_action(
			TaskRegistry::COLLECT_ACTION,
			static function ( TaskRegistry $registry ) {
				$registry->register_classes( [ self::class ] );
			}
		);
	}

	public function descriptor(): array {
		return [
			'id'             => 'block-patterns-cache',
			'label'          => __( 'Cached pattern library', 'templately' ),
			'group'          => 'cache',
			'scope'          => 'both',
			'retention_kind' => RetentionKind::INTRINSIC,
			'destructive'    => true,
			'schedulable'    => false,
		];
	}

	public function estimate( Context $context ): TaskResult {
		return $this->clear( $context->with_dry_run( true ) );
	}

	public function run( Context $context ): TaskResult {
		return $this->clear( $context );
	}

	private function clear( Context $context ): TaskResult {
		$sync = PatternSync::get_instance();
		$dir  = $sync->content_dir();

		$result = TaskResult::empty();

		$files = glob( rtrim( $dir, '/\\' ) . '/*.php' );
		foreach ( (array) $files as $file ) {
			if ( 'index' === basename( $file, '.php' ) ) {
				// The directory's own guard file — never a candidate.
				continue;
			}

			$outcome = Remover::delete_file( $file, $context );

			if ( $outcome instanceof WP_Error ) {
				$result->fail( sprintf( '%s: %s', basename( $file ), $outcome->get_error_message() ) );
				continue;
			}

			$result->merge( $outcome );
		}

		if ( ! $context->is_dry_run() ) {
			$sync->invalidate_all();
		}

		return $result;
	}
}
