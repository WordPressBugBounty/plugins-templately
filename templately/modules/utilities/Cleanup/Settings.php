<?php
/**
 * Persisted, administrator-editable retention settings.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup;

use WP_Error;

/**
 * Plain `get_option`/`update_option`, deliberately NOT `Templately\Utils\Options`:
 * that helper scopes to USER options on multisite, so a value written in one
 * scope can be read from another. Retention is a property of the site, and
 * `$wpdb->options` is already per-site on multisite — which is exactly the
 * scoping FR-036 requires, for free.
 *
 * Values are validated and REFUSED when out of range rather than silently
 * clamped. Silently clamping an administrator's "0 days" to 1 tells them their
 * setting was accepted when it was not.
 */
final class Settings {

	const OPTION = 'templately_cleanup_settings';

	const DEFAULT_AGE_DAYS      = 7;
	const DEFAULT_CEILING_BYTES = 1073741824; // 1 GiB.
	const DEFAULT_LOG_KEEP      = 10;

	/** Below a day risks deleting an import someone paused overnight. */
	const MIN_AGE_DAYS = 1;
	/** Past a year the size ceiling is doing all the work anyway. */
	const MAX_AGE_DAYS = 365;

	/**
	 * A ceiling under ~100 MiB would trip on a single ordinary template pack,
	 * making every run destructive.
	 */
	const MIN_CEILING_BYTES = 104857600;

	const MIN_LOG_KEEP = 1;
	const MAX_LOG_KEEP = 1000;

	public static function defaults(): array {
		return [
			'age_days'        => self::DEFAULT_AGE_DAYS,
			'ceiling_bytes'   => self::DEFAULT_CEILING_BYTES,
			'ceiling_enabled' => true,
			'log_keep'        => self::DEFAULT_LOG_KEEP,
			'disabled'        => false,
		];
	}

	public static function get(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$settings = array_merge( self::defaults(), $stored );

		// Cast defensively: an option written by an older version, or by hand,
		// must not hand a string into arithmetic.
		$settings['age_days']        = (int) $settings['age_days'];
		$settings['ceiling_bytes']   = (int) $settings['ceiling_bytes'];
		$settings['ceiling_enabled'] = (bool) $settings['ceiling_enabled'];
		$settings['log_keep']        = (int) $settings['log_keep'];
		$settings['disabled']        = (bool) $settings['disabled'];

		return $settings;
	}

	/**
	 * Validate and persist a partial update.
	 *
	 * @param array $changes Only the keys present are written.
	 * @return array|WP_Error The stored settings, or the first validation failure.
	 */
	public static function update( array $changes ) {
		$current = self::get();

		if ( array_key_exists( 'age_days', $changes ) ) {
			$age = (int) $changes['age_days'];
			if ( $age < self::MIN_AGE_DAYS || $age > self::MAX_AGE_DAYS ) {
				return new WP_Error(
					'invalid_retention',
					sprintf(
						/* translators: 1: minimum days, 2: maximum days. */
						__( 'Retention must be between %1$d and %2$d days.', 'templately' ),
						self::MIN_AGE_DAYS,
						self::MAX_AGE_DAYS
					),
					[ 'status' => 400, 'field' => 'age_days' ]
				);
			}
			$current['age_days'] = $age;
		}

		if ( array_key_exists( 'ceiling_bytes', $changes ) ) {
			$ceiling = (int) $changes['ceiling_bytes'];
			if ( $ceiling < self::MIN_CEILING_BYTES ) {
				return new WP_Error(
					'invalid_ceiling',
					sprintf(
						/* translators: %s: minimum size, already formatted (e.g. "100 MB"). */
						__( 'The storage limit must be at least %s — a smaller limit would be reached by a single template pack.', 'templately' ),
						size_format( self::MIN_CEILING_BYTES )
					),
					[ 'status' => 400, 'field' => 'ceiling_bytes' ]
				);
			}
			$current['ceiling_bytes'] = $ceiling;
		}

		if ( array_key_exists( 'ceiling_enabled', $changes ) ) {
			$current['ceiling_enabled'] = (bool) $changes['ceiling_enabled'];
		}

		if ( array_key_exists( 'log_keep', $changes ) ) {
			$keep = (int) $changes['log_keep'];
			if ( $keep < self::MIN_LOG_KEEP || $keep > self::MAX_LOG_KEEP ) {
				return new WP_Error(
					'invalid_log_keep',
					sprintf(
						/* translators: 1: minimum count, 2: maximum count. */
						__( 'Keep between %1$d and %2$d import logs.', 'templately' ),
						self::MIN_LOG_KEEP,
						self::MAX_LOG_KEEP
					),
					[ 'status' => 400, 'field' => 'log_keep' ]
				);
			}
			$current['log_keep'] = $keep;
		}

		if ( array_key_exists( 'disabled', $changes ) ) {
			$current['disabled'] = (bool) $changes['disabled'];
		}

		update_option( self::OPTION, $current, false );

		return $current;
	}

	/**
	 * Whether cleanup is switched off entirely — by an administrator, or in code.
	 *
	 * When true no schedule is registered and no task runs.
	 */
	public static function is_disabled(): bool {
		$settings = self::get();

		/**
		 * Disable all cleanup.
		 *
		 * @param bool $disabled
		 */
		return (bool) apply_filters( 'templately_cleanup_disabled', $settings['disabled'] );
	}

	/**
	 * Test seam.
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
	}
}
