<?php

namespace Templately\Modules\FullSiteImport\Concerns;

use Elementor\Plugin;
use Error;
use Exception;
use Templately\Modules\FullSiteImport\Exception\FatalErrorException;
use Templately\Modules\FullSiteImport\Exception\RetryableErrorException;
use Templately\Modules\FullSiteImport\Exception\UnknownErrorException;
use Templately\Modules\FullSiteImport\Runners\Finalizer;
use Templately\Modules\FullSiteImport\Utils\LogHandler;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Modules\FullSiteImport\Utils\AIUtils;
use Templately\Utils\Helper;
use Templately\Modules\Utilities\Cleanup\Scheduler;
use Templately\Utils\Options;

/**
 * CleansUp — extracted verbatim from FullSiteImport (behavior-preserving).
 * Composed back into FullSiteImport via `use`; $this and method resolution unchanged.
 */
trait CleansUp {
	/*
	 * schedule_cleanup_cron() and run_scheduled_cleanup() lived here until spec
	 * 052. This module no longer owns a cleanup schedule: `modules/utilities/`
	 * runs the ONE sweep and this module contributes tasks to it (see
	 * Cleanup/CleanupTasks.php). The old `templately_fsi_daily_cleanup` event is
	 * unscheduled by that module's Scheduler — an orphaned recurring event keeps
	 * firing forever on sites that already stored it, even once the code that
	 * registered it is gone.
	 *
	 * The callback was one line, `SessionData::cleanup_expired(7)`, and pruned
	 * OPTIONS ONLY. It never touched the filesystem, which is why abandoned
	 * imports leaked their extracted packs indefinitely.
	 */

	private function clear_data_file($request_params){
		if(defined('TEMPLATELY_DEV') && TEMPLATELY_DEV){
			return;
		}

		// Handle directory cleanup
		Utils::cleanup_directory($this->dir_path);

		// Always save to preview directory for AI content workflow
		$session_id = $request_params['session_id'] ?? '';
		$pack_id = $request_params['id'] ?? '';

		// Set up directory paths for cleanup
		$root_dir = $request_params['root_dir'] ?? untrailingslashit(Helper::upload_dir('tmp'));
		$prv_dir = $request_params['prv_dir'] ?? untrailingslashit(Helper::upload_dir('preview'));

		$processed_data = AIUtils::get_ai_process_data_by_session_id($session_id);

		// Pack-scoped session cleanup is handled solely by the 7-day retention cron
		// (034 FR-005) — the previously-disabled clean_by_pack_id() has been removed.

		// Keep only the CURRENT pack's superseded AI process rows — cheap, and
		// scoped to the import that just finished.
		$current_process_id = !empty($processed_data['process_id']) ? $processed_data['process_id'] : null;
		AIUtils::clean_ai_process_data_by_pack_id($pack_id, $current_process_id);

		// The orphan sweep itself is no longer a lottery. It used to sit behind
		// `mt_rand(1, 100) <= 5` and require a live pack id and session id, so
		// four out of five finished imports swept nothing and an import that
		// never finished swept nothing ever. Nudging the shared cleanup service
		// runs it every time, asynchronously, off the critical path — and it
		// works from the retention window alone, with no in-flight identifiers.
		Scheduler::kick();
	}

	/**
	 * Directory-based cleanup for session data directories
	 * Scans the actual filesystem directories and removes directories that match cleanup criteria
	 *
	 * @param string $root_dir The root directory containing session directories
	 * @param string $pack_id The pack ID to match for cleanup
	 * @param string $current_session_id The current session ID to preserve
	 */
	private function cleanup_session_directories($root_dir, $pack_id, $current_session_id) {
		if (empty($root_dir) || !is_dir($root_dir) || empty($pack_id) || empty($current_session_id)) {
			return;
		}

		try {
			// Get all session data to check pack_id associations
			$all_session_data = SessionData::get_all_data();

			// Scan the actual directories in the filesystem
			$directories = scandir($root_dir);
			if ($directories === false) {
				return;
			}

			foreach ($directories as $dir_name) {
				// Skip current directory, parent directory, and current session
				if ($dir_name === '.' || $dir_name === '..' || $dir_name === $current_session_id) {
					continue;
				}

				$dir_path = trailingslashit($root_dir) . $dir_name;

				// Only process actual directories
				if (!is_dir($dir_path)) {
					continue;
				}

				// Check if this directory should be cleaned up
				$should_cleanup = false;

				// If we have session data for this directory, check if it matches the pack_id
				if (isset($all_session_data[$dir_name]) &&
					isset($all_session_data[$dir_name]['id']) &&
					$all_session_data[$dir_name]['id'] === $pack_id) {
					$should_cleanup = true;
				} else if (!isset($all_session_data[$dir_name])) {
					// This is an orphaned directory with no corresponding session data
					$should_cleanup = true;
				}

				if ($should_cleanup) {
					Utils::cleanup_directory($dir_path);

					if (defined('TEMPLATELY_DEV') && TEMPLATELY_DEV) {
						Helper::log('Cleaned up session directory: ' . $dir_name, 'fsi_cleanup');
					}
				}
			}
		} catch (Exception $e) {
			if (defined('TEMPLATELY_DEV') && TEMPLATELY_DEV) {
				Helper::log('Error during session directory cleanup: ' . $e->getMessage(), 'fsi_cleanup', 'error');
			}
		}
	}

	/**
	 * Directory-based cleanup for AI process data directories
	 * Scans the actual filesystem directories and removes directories that match cleanup criteria
	 *
	 * @param string $prv_dir The preview directory containing process directories
	 * @param string $pack_id The pack ID to match for cleanup
	 * @param string $current_process_id The current process ID to preserve (optional)
	 */
	private function cleanup_ai_process_directories($prv_dir, $pack_id, $current_process_id = null) {
		if (empty($prv_dir) || !is_dir($prv_dir) || empty($pack_id)) {
			return;
		}

		try {
			// Get all AI process data to check pack_id associations
			$ai_process_data = AIUtils::get_ai_process_data();

			// Scan the actual directories in the filesystem
			$directories = scandir($prv_dir);
			if ($directories === false) {
				return;
			}

			foreach ($directories as $dir_name) {
				// Skip current directory, parent directory, and current process
				if ($dir_name === '.' || $dir_name === '..' ||
					(!empty($current_process_id) && $dir_name === $current_process_id)) {
					continue;
				}

				$dir_path = trailingslashit($prv_dir) . $dir_name;

				// Only process actual directories
				if (!is_dir($dir_path)) {
					continue;
				}

				// Check if this directory should be cleaned up
				$should_cleanup = false;

				// If we have process data for this directory, check if it matches the pack_id
				if (isset($ai_process_data[$dir_name]) &&
					is_array($ai_process_data[$dir_name]) &&
					isset($ai_process_data[$dir_name]['pack_id']) &&
					$ai_process_data[$dir_name]['pack_id'] === $pack_id) {
					$should_cleanup = true;
				} else if (!isset($ai_process_data[$dir_name])) {
					// This is an orphaned directory with no corresponding process data
					$should_cleanup = true;
				}

				if ($should_cleanup) {
					Utils::cleanup_directory($dir_path);

					if (defined('TEMPLATELY_DEV') && TEMPLATELY_DEV) {
						Helper::log('Cleaned up AI process directory: ' . $dir_name, 'fsi_cleanup');
					}
				}
			}
		} catch (Exception $e) {
			if (defined('TEMPLATELY_DEV') && TEMPLATELY_DEV) {
				Helper::log('Error during AI process directory cleanup: ' . $e->getMessage(), 'fsi_cleanup', 'error');
			}
		}
	}

}
