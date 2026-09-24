<?php

namespace Templately\Utils\Log;

/**
 * The file sink behind `Helper::log()` / `Logger` — a dedicated log file under
 * the uploads dir instead of the shared `wp-content/debug.log`.
 *
 * Why not debug.log: Templately's log carries the server-side detail spec 043
 * deliberately keeps OFF the wire (stack traces, upstream bodies, file paths).
 * Mixing it into the site-wide debug.log buries it among every other plugin's
 * noise and couples support diagnostics to a file the host may rotate, disable,
 * or expose. A dedicated file is also what the dev `log-viewer` module already
 * lists (`uploads/templately/log/*.log`).
 *
 * Security — the uploads dir is web-reachable, so the file must not be
 * publicly fetchable:
 *  - the filename embeds an unguessable per-site hash derived from `wp_salt()`
 *    (the primary defense — works on every server),
 *  - the directory gets an `.htaccess` deny (Apache) and a blank `index.php`
 *    (defense-in-depth; nginx ignores `.htaccess`, hence the hash).
 *
 * Rotation: one previous generation is kept. When the live file exceeds
 * MAX_BYTES it is renamed `*-old.log` (replacing any prior generation) and a
 * fresh file starts — bounded disk use, and the tail of the previous window
 * survives for support.
 *
 * If the uploads dir is unavailable/unwritable the line falls back to
 * `error_log()` — a degraded destination beats a silently dropped log.
 *
 * Gate-free by design: callers own their gates (`Helper::log()` checks
 * `WP_DEBUG_LOG`; dev-kernel checks its own feature flag). This class only
 * answers "where do log lines go".
 */
class LogFile {

	/**
	 * Rotate when the live file exceeds this. 5 MB keeps weeks of typical
	 * output while bounding worst-case disk use to ~10 MB (live + old).
	 */
	const MAX_BYTES = 5242880;

	/**
	 * Memoized resolved path for this request.
	 * null = not resolved yet; false = uploads unavailable (fall back).
	 *
	 * @var string|false|null
	 */
	private static $path = null;

	/**
	 * Append one already-formatted line to the Templately log file.
	 *
	 * @param string $line Formatted log line (no trailing newline needed).
	 * @return void
	 */
	public static function write( $line ) {
		$path = self::path();

		if ( ! $path ) {
			error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate fallback when uploads is unwritable.
			return;
		}

		self::maybe_rotate( $path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- append with LOCK_EX; WP_Filesystem has no locking append.
		$written = @file_put_contents( $path, $line . PHP_EOL, FILE_APPEND | LOCK_EX );

		if ( false === $written ) {
			error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate fallback when the write fails.
		}
	}

	/**
	 * Resolve (and memoize) the full path of the live log file, provisioning
	 * the directory and its guard files on first use.
	 *
	 * @return string|false Absolute path, or false when uploads is unusable.
	 */
	public static function path() {
		if ( null !== self::$path ) {
			return self::$path;
		}

		$upload_dir = wp_upload_dir( null, false );

		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			self::$path = false;
			return self::$path;
		}

		// Matches where the dev log-viewer module already looks
		// (uploads/templately/log/*.log) — per-site on multisite via basedir.
		$dir = $upload_dir['basedir'] . '/templately/log';

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			self::$path = false;
			return self::$path;
		}

		self::ensure_guards( $dir );

		self::$path = $dir . '/' . self::filename();
		return self::$path;
	}

	/**
	 * The per-site log filename: deterministic (so every request appends to the
	 * same file) but unguessable from outside (derived from the site's salts).
	 *
	 * @return string
	 */
	public static function filename() {
		$hash = substr( md5( wp_salt( 'auth' ) . '|templately-log-file' ), 0, 12 );

		return "templately-{$hash}.log";
	}

	/**
	 * Forget the memoized path (tests; or after switch_to_blog when a caller
	 * needs the new site's file).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$path = null;
	}

	/**
	 * Drop the deny/index guard files into the log dir once.
	 *
	 * @param string $dir Log directory (exists).
	 * @return void
	 */
	private static function ensure_guards( $dir ) {
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Deny direct access to Templately log files.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-time guard file, best-effort.
			@file_put_contents( $htaccess, $rules );
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-time guard file, best-effort.
			@file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
	}

	/**
	 * Rotate the live file out to `*-old.log` when it exceeds MAX_BYTES,
	 * keeping exactly one previous generation.
	 *
	 * @param string $path Live log file path.
	 * @return void
	 */
	private static function maybe_rotate( $path ) {
		if ( ! file_exists( $path ) ) {
			return;
		}

		$size = @filesize( $path );
		if ( false === $size || $size < self::MAX_BYTES ) {
			return;
		}

		$old = substr( $path, 0, -4 ) . '-old.log';

		// Windows rename() fails onto an existing target — clear it first.
		if ( file_exists( $old ) ) {
			@unlink( $old );
		}

		@rename( $path, $old );
	}
}
