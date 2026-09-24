<?php
/**
 * What the plugin is using on disk, and how much room is left.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup;

/**
 * A thin, UI-shaped view over the scanner.
 *
 * The `partial` flag is load-bearing, not decoration. A scan that hit its time
 * budget must be reported AS partial — presenting a partial figure as a total
 * tells a user with 8 GB of leftovers that they have 300 MB, and "nothing much
 * to clean" is the one wrong answer this screen can give.
 */
final class UsageReport {

	/**
	 * @param bool        $refresh      Bypass the 5-minute cache.
	 * @param string|null $continuation Resume a scan that ran out of budget.
	 */
	public static function build( bool $refresh = false, ?string $continuation = null ): array {
		$payload = Scanner::get_usage(
			[
				'refresh'  => $refresh,
				'continue' => (bool) $continuation,
			]
		);

		$areas = [];
		foreach ( (array) ( $payload['subdirs'] ?? [] ) as $entry ) {
			$name           = isset( $entry['name'] ) ? (string) $entry['name'] : '';
			$areas[ $name ] = [
				'bytes' => (int) ( $entry['bytes'] ?? 0 ),
				'items' => (int) ( $entry['files'] ?? 0 ),
				'label' => self::label_for( $name ),
			];
		}

		$loose_bytes = (int) ( $payload['loose_bytes'] ?? 0 );
		if ( $loose_bytes > 0 ) {
			$areas['_loose'] = [
				'bytes' => $loose_bytes,
				'items' => (int) ( $payload['loose_files'] ?? 0 ),
				'label' => __( 'Other files', 'templately' ),
			];
		}

		$done = ! empty( $payload['done'] );

		return [
			'exists'      => ! empty( $payload['exists'] ),
			'areas'       => $areas,
			'total_bytes' => (int) ( $payload['total_bytes'] ?? 0 ),
			'total_items' => (int) ( $payload['total_files'] ?? 0 ),
			// A caller must be able to tell "we measured everything and it is
			// small" from "we ran out of time and this is what we got so far".
			'partial'      => ! $done,
			'continuation' => $done ? null : 'resume',
			'free_bytes'   => self::free_bytes(),
			'cached'       => ! empty( $payload['cached'] ),
		];
	}

	/**
	 * Human-readable name for a top-level directory.
	 *
	 * Falls back to the raw directory name rather than hiding an area the UI
	 * does not recognise — an unexplained 4 GB is still worth showing.
	 */
	private static function label_for( string $name ): string {
		switch ( $name ) {
			case 'tmp':
				return __( 'Import working files', 'templately' );
			case 'preview':
				return __( 'AI preview files', 'templately' );
			case 'log':
				return __( 'Logs', 'templately' );
			case 'patterns':
				return __( 'Cached patterns', 'templately' );
			default:
				return $name;
		}
	}

	/**
	 * Free space on the volume holding the uploads directory.
	 *
	 * Null where the host disables the check — reported as unknown rather than
	 * as zero, which would read as "the disk is full".
	 */
	private static function free_bytes(): ?int {
		$base = Scanner::get_base_dir();
		$dir  = is_dir( $base ) ? $base : dirname( $base );

		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return ( false === $free || null === $free ) ? null : (int) $free;
	}
}
