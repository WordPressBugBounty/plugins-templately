<?php
/**
 * FSI's contribution to the shared cleanup service.
 *
 * @package Templately
 */

namespace Templately\Modules\FullSiteImport\Cleanup;

use Templately\Modules\Utilities\Cleanup\TaskRegistry;

/**
 * The one place this module names its cleanup tasks.
 *
 * Contribution is inverted: `modules/utilities/` never refers to full-site-import,
 * so removing or disabling this module removes its cleanups with it and the
 * engine needs no edit. This module declares `'utilities'` as a dependency;
 * the arrow points one way only.
 *
 * Four tasks, deliberately separate rather than one "clean up FSI" blob: each
 * reclaims a different kind of thing, each can fail independently without
 * costing the others their run, and the UI can estimate and offer them
 * individually.
 */
final class CleanupTasks {

	public static function register(): void {
		add_action(
			TaskRegistry::COLLECT_ACTION,
			static function ( TaskRegistry $registry ) {
				$registry->register_classes(
					[
						SessionsTask::class,
						TmpDirsTask::class,
						PreviewDirsTask::class,
						LogsTask::class,
					]
				);
			}
		);
	}
}
