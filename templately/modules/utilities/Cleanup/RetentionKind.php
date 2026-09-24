<?php
/**
 * How a cleanup task decides what is expired.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup;

/**
 * Three kinds, because "expired" genuinely means three different things across
 * the plugin's disposable data — and collapsing them into one age window is a
 * defect, not a simplification:
 *
 *  - AGE       — untouched for longer than the ONE shared retention window.
 *                Import sessions, AI process records, and the working
 *                directories that belong to them. These already all used 7 days;
 *                sharing the window removes the possibility of future drift.
 *  - COUNT     — keep the newest N, whatever their age. Import logs. An age rule
 *                here deletes the log of a week-old failure support is still
 *                investigating, and keeps two hundred from one busy afternoon.
 *  - INTRINSIC — the writer recorded an expiry on each item; honour that and
 *                ignore both the window and the count. Plugin cache entries, the
 *                cached pattern library, agent approval records. Applying the
 *                shared window here would delete cache the plugin still
 *                considers live — and the pattern library rebuilds over the
 *                network, so that costs real requests.
 *
 * Not a PHP 8.1 enum: this plugin supports PHP 7.4.
 */
final class RetentionKind {

	const AGE       = 'age';
	const COUNT     = 'count';
	const INTRINSIC = 'intrinsic';

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return [ self::AGE, self::COUNT, self::INTRINSIC ];
	}

	/**
	 * @param mixed $kind
	 * @return bool
	 */
	public static function is_valid( $kind ): bool {
		return is_string( $kind ) && in_array( $kind, self::all(), true );
	}

	/**
	 * Whether the shared age window applies to this kind.
	 *
	 * @param string $kind
	 * @return bool
	 */
	public static function uses_age( string $kind ): bool {
		return self::AGE === $kind;
	}
}
