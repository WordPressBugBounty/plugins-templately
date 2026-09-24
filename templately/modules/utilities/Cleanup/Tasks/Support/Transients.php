<?php
/**
 * Templately transient scan/delete support helpers.
 *
 * Moved VERBATIM from modules/developer-tools/Developer.php (the
 * `get_templately_transients*` / `delete_templately_transients` / `get_last_transient_cleanup`
 * / `get_transient_management_info` static methods) so the behaviour — the `_transient__templately_`
 * prefix scan, the `_transient_timeout_%` exclusion, the per-transient delete loop, the
 * `templately_last_transient_cleanup` option write, and the returned array shapes — stays
 * byte-identical. Only the namespace and the `self::debug_log()` → {@see Helper::log()}
 * call sites changed (the old `Developer::debug_log()` was itself a thin delegate to Kernel).
 *
 * Option key is unchanged: `templately_last_transient_cleanup`.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup\Tasks\Support;

use Templately\Utils\Helper;

class Transients {

	/**
	 * Derive a transient key from its stored option name.
	 *
	 * The one pure fragment of {@see delete_templately_transients()} — extracted so it can be
	 * unit-tested without a DB. Behaviour-neutral: it is the exact
	 * `str_replace( '_transient_', '', … )` the delete loop always used (strips the WordPress
	 * `_transient_` option prefix so the remainder can be passed to `delete_transient()`).
	 *
	 * @param string $option_name The `wp_options` option_name (e.g. `_transient__templately_foo`).
	 * @return string The transient key (e.g. `_templately_foo`).
	 */
	public static function option_name_to_transient_key( $option_name ) {
		return str_replace( '_transient_', '', $option_name );
	}

	/**
	 * Get all Templately transients from the database
	 *
	 * @since 3.4.0
	 * @return array Array of transient objects with option_name
	 */
	public static function get_templately_transients() {
		global $wpdb;

		// Get all transients that start with '_templately_' prefix
		// Exclude timeout entries as they are automatically handled by WordPress
		// Only select option_name to avoid exposing cached data values
		$transients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				 AND option_name NOT LIKE %s
				 ORDER BY option_name",
				'_transient__templately_%',
				'_transient_timeout_%'
			)
		);

		Helper::log( sprintf( 'Found %d Templately transients', count( $transients ) ), 'transient_management' );

		return $transients ?: [];
	}

	/**
	 * Get count of Templately transients
	 *
	 * @since 3.4.0
	 * @return int Number of Templately transients
	 */
	public static function get_templately_transients_count() {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				 AND option_name NOT LIKE %s",
				'_transient__templately_%',
				'_transient_timeout_%'
			)
		);

		return intval( $count );
	}

	/**
	 * Delete all Templately transients
	 *
	 * @since 3.4.0
	 * @return array Result array with success status, deleted count, and message
	 */
	public static function delete_templately_transients() {
		Helper::log( 'Starting Templately transients deletion', 'transient_management' );

		// Get all Templately transients before deletion for logging
		$transients  = self::get_templately_transients();
		$total_found = count( $transients );

		if ( $total_found === 0 ) {
			Helper::log( 'No Templately transients found to delete', 'transient_management' );
			return [
				'success'       => true,
				'deleted_count' => 0,
				'total_found'   => 0,
				'message'       => __( 'No Templately transients found to delete.', 'templately' ),
			];
		}

		$deleted_count    = 0;
		$failed_deletions = [];

		// Delete each transient using WordPress delete_transient function
		foreach ( $transients as $transient ) {
			// Extract the key by removing the '_transient_' prefix
			$key = self::option_name_to_transient_key( $transient->option_name );

			if ( delete_transient( $key ) ) {
				$deleted_count++;
				Helper::log( "Successfully deleted transient: {$key}", 'transient_management' );
			} else {
				$failed_deletions[] = $key;
				Helper::log( "Failed to delete transient: {$key}", 'transient_management' );
			}
		}

		// Store deletion timestamp and statistics
		$deletion_info = [
			'timestamp'     => current_time( 'mysql' ),
			'deleted_count' => $deleted_count,
			'total_found'   => $total_found,
			'failed_count'  => count( $failed_deletions ),
		];

		update_option( 'templately_last_transient_cleanup', $deletion_info );

		// Log summary
		Helper::log(
			sprintf(
				'Transient deletion completed: %d/%d deleted successfully',
				$deleted_count,
				$total_found
			),
			'transient_management'
		);

		if ( ! empty( $failed_deletions ) ) {
			Helper::log(
				sprintf( 'Failed to delete %d transients: %s', count( $failed_deletions ), implode( ', ', $failed_deletions ) ),
				'transient_management'
			);
		}

		$success = $deleted_count > 0;
		$message = $success
			? sprintf(
				/* translators: %1$d: number of deleted transients, %2$d: total found transients */
				__( 'Successfully deleted %1$d of %2$d Templately transients.', 'templately' ),
				$deleted_count,
				$total_found
			)
			: __( 'Failed to delete any transients.', 'templately' );

		return [
			'success'       => $success,
			'deleted_count' => $deleted_count,
			'total_found'   => $total_found,
			'failed_count'  => count( $failed_deletions ),
			'failed_keys'   => $failed_deletions,
			'message'       => $message,
		];
	}

	/**
	 * Get last transient cleanup information
	 *
	 * @since 3.4.0
	 * @return array|false Cleanup information or false if never run
	 */
	public static function get_last_transient_cleanup() {
		return get_option( 'templately_last_transient_cleanup', false );
	}

	/**
	 * Get transient management information for API responses
	 *
	 * @since 3.4.0
	 * @return array Transient management data
	 */
	public static function get_transient_management_info() {
		$count        = self::get_templately_transients_count();
		$last_cleanup = self::get_last_transient_cleanup();

		return [
			'transient_count' => $count,
			'last_cleanup'    => $last_cleanup,
			'has_transients'  => $count > 0,
		];
	}
}
