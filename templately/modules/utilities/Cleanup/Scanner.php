<?php
/**
 * Uploads scanner — disk usage + destructive-delete support for
 * `wp-content/uploads/templately/`.
 *
 * A brand-new developer feature (no legacy counterpart). Three responsibilities, all
 * static so the REST controller stays a thin transport shell:
 *
 *  - {@see get_usage()}   — per-subdirectory sizing of the Templately uploads root, cached
 *                           in the `_templately_uploads_usage` transient for 5 minutes.
 *  - {@see resolve_target()} — the ONE path-validation gate every destructive call goes
 *                           through (realpath + strict trailing-separator prefix check).
 *  - {@see delete_tree()} — the recursive removal itself.
 *
 * TIME BUDGET (mirrors {@see \Templately\Utils\Helper::fsi_should_exit()}): a pathological
 * uploads tree must never wedge the request. The scan works in whole top-level
 * subdirectories and checks {@see should_exit()} after EACH one — so forward progress is
 * guaranteed (at least one subdirectory per call, never a zero-work loop) — then returns
 * `done => false` with the remaining work parked in `cursor`. The partial payload is stored
 * in the same transient, so the next call only needs `continue => true` (an opaque
 * continuation flag) rather than round-tripping a nested cursor structure through REST.
 *
 * SYMLINKS ARE NEVER FOLLOWED. `RecursiveDirectoryIterator::hasChildren()` defaults to
 * `$allow_links = false`, and every loop additionally skips `isLink()` entries before the
 * `isDir()` branch (a symlink to a directory answers `isDir() === true`). A symlink loop
 * therefore cannot hang a scan, and a delete unlinks the LINK, never its target.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use UnexpectedValueException;
use WP_Error;

class Scanner {

	/**
	 * Transient key holding the last usage payload (complete OR partial).
	 *
	 * NOTE: passed to set_transient()/get_transient() verbatim — WordPress adds its own
	 * `_transient_` option prefix on top.
	 */
	const TRANSIENT_KEY = '_templately_uploads_usage';

	/** Cache lifetime for a usage payload, in seconds (5 minutes). */
	const CACHE_TTL = 300;

	/** Default soft wall-clock budget for one scan pass, in seconds. */
	const DEFAULT_BUDGET = 5.0;

	/** Directory name under the uploads basedir that this module owns. */
	const UPLOADS_SUBDIR = 'templately';

	/**
	 * Absolute path of the Templately uploads root (no trailing separator).
	 *
	 * `wp_upload_dir( null, false )` — the `$create_dir = false` argument matters: the
	 * default would create the current month's directory as a side effect of merely
	 * READING a usage figure.
	 *
	 * @return string
	 */
	public static function get_base_dir() {
		$uploads = wp_upload_dir( null, false );
		$basedir = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';

		$dir = rtrim( $basedir, '/\\' ) . DIRECTORY_SEPARATOR . self::UPLOADS_SUBDIR;

		/**
		 * Redirect the root every cleanup task resolves against.
		 *
		 * EVERY CLEANUP TEST MUST USE THIS. A test that runs against the real
		 * uploads directory deletes the developer's own working data — see the
		 * docblock on PatternSync::content_dir(), which records exactly that
		 * happening to a live sandbox.
		 *
		 * @param string $dir Absolute path, no trailing separator.
		 */
		return (string) apply_filters( 'templately_cleanup_base_dir', $dir );
	}

	/**
	 * Soft per-pass wall-clock budget in seconds.
	 *
	 * @return float
	 */
	public static function get_budget() {
		/**
		 * Filter the uploads-scanner per-pass time budget (seconds).
		 *
		 * @param float $budget Default 5.0.
		 */
		return (float) apply_filters( 'templately_uploads_scanner_budget', self::DEFAULT_BUDGET );
	}

	/**
	 * Whether the current scan pass should stop and return a partial result.
	 *
	 * Two independent ceilings, mirroring Helper::fsi_should_exit():
	 *  1. the scanner's own soft budget (elapsed time in THIS pass), and
	 *  2. the request-level `max_execution_time`, bailing once we are within
	 *     `max( 5, 20% of the limit )` seconds of it.
	 *
	 * Ceiling 2 shares Helper::fsi_should_exit()'s dependency on `TEMPLATELY_START_TIME`,
	 * which only the import runners define — so outside an import it is simply inert and
	 * the soft budget is the operative guard. That is deliberate: the budget alone already
	 * bounds a pass, and the second ceiling adds protection when a scan happens to run
	 * inside a long-lived import request.
	 *
	 * @param float      $started_at microtime(true) when the pass started.
	 * @param float|null $budget     Soft budget override; null uses {@see get_budget()}.
	 * @return bool
	 */
	public static function should_exit( $started_at, $budget = null ) {
		if ( null === $budget ) {
			$budget = self::get_budget();
		}

		$budget = (float) $budget;
		if ( $budget >= 0 && ( microtime( true ) - $started_at ) >= $budget ) {
			return true;
		}

		$max_time = (int) ini_get( 'max_execution_time' );
		if ( defined( 'TEMPLATELY_START_TIME' ) && $max_time > 0 ) {
			$elapsed = microtime( true ) - TEMPLATELY_START_TIME;
			$delay   = max( 5, $max_time * 20 / 100 );

			if ( $max_time - $elapsed <= $delay ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Usage payload for the Templately uploads root.
	 *
	 * Returned shape:
	 *   total_bytes  int    Sum of every measured file (subdirectories + loose root files).
	 *   total_files  int    File count behind total_bytes.
	 *   subdirs      array  [ { name, bytes, files } ], largest first.
	 *   loose_bytes  int    Bytes of files sitting directly in the root (not in `subdirs`).
	 *   loose_files  int    File count behind loose_bytes.
	 *   done         bool   false = the time budget cut the pass short; call again with
	 *                       `continue => true` to resume.
	 *   cursor       array|null Remaining work when `done` is false (internal).
	 *   cached_at    int    Unix timestamp the payload was produced.
	 *   cached       bool   true = served from the transient without touching the disk.
	 *   exists       bool   false = the uploads/templately directory is not there.
	 *   base         string Absolute path that was measured.
	 *
	 * @param array $args {
	 *     @type bool        $refresh  Bypass + delete the cached payload and rescan.
	 *     @type bool        $continue Resume a partial (`done => false`) cached payload.
	 *     @type float|null  $budget   Soft time budget override (seconds). Testing/internal.
	 *     @type string|null $base     Scan this directory instead of the real uploads root.
	 *                                 Testing/internal — an explicit base BYPASSES the shared
	 *                                 transient entirely so fixtures never poison the cache.
	 * }
	 * @return array
	 */
	public static function get_usage( $args = [] ) {
		$args = wp_parse_args(
			$args,
			[
				'refresh'  => false,
				'continue' => false,
				'budget'   => null,
				'base'     => null,
			]
		);

		$explicit_base = ! empty( $args['base'] );
		$base          = $explicit_base ? rtrim( $args['base'], '/\\' ) : self::get_base_dir();
		$cacheable     = ! $explicit_base;

		$cached = false;
		if ( $cacheable ) {
			if ( ! empty( $args['refresh'] ) ) {
				delete_transient( self::TRANSIENT_KEY );
			} else {
				$cached = get_transient( self::TRANSIENT_KEY );
			}
		}

		$resume = null;
		if ( is_array( $cached ) ) {
			$is_partial = empty( $cached['done'] ) && ! empty( $cached['cursor'] );

			// A complete payload (or any payload the caller did not ask to continue) is
			// served straight from cache — this is the "second call is cached" path.
			if ( ! $is_partial || empty( $args['continue'] ) ) {
				$cached['cached'] = true;
				return $cached;
			}

			$resume = $cached['cursor'];
		}

		$payload = self::run_scan( $base, $resume, $args['budget'] );

		if ( $cacheable ) {
			set_transient( self::TRANSIENT_KEY, $payload, self::CACHE_TTL );
		}

		return $payload;
	}

	/**
	 * Delete the cached usage payload. Called after every successful destructive action.
	 *
	 * @return void
	 */
	public static function bust_cache() {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Resolve + VALIDATE a delete target against the Templately uploads root.
	 *
	 * This is the single security gate for every destructive call. It is deliberately
	 * paranoid:
	 *  - the target is resolved with `realpath()`, so `..` segments AND symlinks are
	 *    collapsed BEFORE the containment test (a symlink inside the root pointing at
	 *    `/etc` resolves to `/etc` and is rejected);
	 *  - containment is a prefix test against `realpath( base ) . DIRECTORY_SEPARATOR`.
	 *    The trailing separator is load-bearing: without it the sibling
	 *    `/uploads/templately-evil` passes a naive `strpos( $real, $base ) === 0` test;
	 *  - an absolute path outside the root is rejected by the same test;
	 *  - a missing path or a non-directory is an ERROR, never a silent success.
	 *
	 * The root itself resolves successfully with `is_root => true` — the extra
	 * `delete_root` confirmation is the CALLER's rail (see the REST controller), kept out
	 * of here so this function stays a pure path question.
	 *
	 * @param string      $target Relative path under the uploads root ('' / '.' / '/' = the
	 *                            root itself). Absolute paths are allowed only if they
	 *                            resolve inside the root.
	 * @param string|null $base   Base directory override (testing/internal); null = the real
	 *                            uploads root.
	 * @return array|WP_Error { path: string, relative: string, is_root: bool }
	 */
	public static function resolve_target( $target, $base = null ) {
		$base = ( null === $base ) ? self::get_base_dir() : $base;

		$base_real = realpath( $base );
		if ( false === $base_real || ! is_dir( $base_real ) ) {
			return new WP_Error(
				'uploads_base_missing',
				__( 'The Templately uploads directory does not exist.', 'templately' ),
				[ 'status' => 404 ]
			);
		}
		$base_real = rtrim( $base_real, '/\\' );

		$target = trim( (string) $target );

		// Root shorthands.
		if ( '' === $target || '.' === $target || '/' === $target || './' === $target ) {
			return [
				'path'     => $base_real,
				'relative' => '',
				'is_root'  => true,
			];
		}

		$candidate = self::is_absolute_path( $target )
			? $target
			: $base_real . DIRECTORY_SEPARATOR . ltrim( $target, '/\\' );

		$real = realpath( $candidate );
		if ( false === $real ) {
			return new WP_Error(
				'target_not_found',
				__( 'That directory does not exist.', 'templately' ),
				[ 'status' => 404 ]
			);
		}
		$real = rtrim( $real, '/\\' );

		if ( $real === $base_real ) {
			return [
				'path'     => $base_real,
				'relative' => '',
				'is_root'  => true,
			];
		}

		// STRICT containment: the trailing separator stops '/uploads/templately-evil'
		// from passing as a child of '/uploads/templately'.
		if ( 0 !== strpos( $real, $base_real . DIRECTORY_SEPARATOR ) ) {
			return new WP_Error(
				'target_outside_base',
				__( 'That path is outside the Templately uploads directory.', 'templately' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! is_dir( $real ) ) {
			return new WP_Error(
				'target_not_a_directory',
				__( 'That path is not a directory.', 'templately' ),
				[ 'status' => 400 ]
			);
		}

		return [
			'path'     => $real,
			'relative' => ltrim( substr( $real, strlen( $base_real ) ), '/\\' ),
			'is_root'  => false,
		];
	}

	/**
	 * NOTE: `delete_tree()` used to live here. It moved to
	 * {@see \Templately\Modules\Utilities\Cleanup\Remover}, which is now the
	 * ONLY public deletion surface in the plugin.
	 *
	 * This class measures and validates paths; it does not delete. Keeping a raw
	 * recursive delete on the same class a task already uses for `resolve_target()`
	 * and `measure_dir()` made bypassing the guard-file denylist and the live-file
	 * exclusion a single autocomplete away. Now it is unreachable.
	 */

	/**
	 * Measure one directory recursively.
	 *
	 * @param string $dir Absolute directory path.
	 * @return array { bytes: int, files: int }
	 */
	public static function measure_dir( $dir ) {
		$measured = [
			'bytes' => 0,
			'files' => 0,
		];

		$iterator = self::make_recursive_iterator( $dir, RecursiveIteratorIterator::LEAVES_ONLY );
		if ( null === $iterator ) {
			// Unreadable (or vanished) directory — tolerated, reported as empty.
			return $measured;
		}

		foreach ( $iterator as $item ) {
			if ( $item->isLink() || ! $item->isFile() ) {
				continue;
			}

			$measured['bytes'] += self::safe_size( $item );
			$measured['files']++;
		}

		return $measured;
	}

	// -----------------------------------------------------------------
	// Internals
	// -----------------------------------------------------------------

	/**
	 * Run one scan pass, optionally resuming a parked cursor.
	 *
	 * @param string     $base   Absolute directory to measure.
	 * @param array|null $resume Cursor from a previous partial pass.
	 * @param float|null $budget Soft time budget override (seconds).
	 * @return array
	 */
	private static function run_scan( $base, $resume, $budget ) {
		$started = microtime( true );

		$payload = [
			'base'        => $base,
			'exists'      => is_dir( $base ),
			'total_bytes' => 0,
			'total_files' => 0,
			'subdirs'     => [],
			'loose_bytes' => 0,
			'loose_files' => 0,
			'done'        => true,
			'cursor'      => null,
			'cached_at'   => time(),
			'cached'      => false,
		];

		if ( ! $payload['exists'] ) {
			return $payload;
		}

		if ( is_array( $resume ) && isset( $resume['pending'] ) ) {
			$pending                = (array) $resume['pending'];
			$payload['subdirs']     = isset( $resume['subdirs'] ) ? (array) $resume['subdirs'] : [];
			$payload['loose_bytes'] = isset( $resume['loose_bytes'] ) ? (int) $resume['loose_bytes'] : 0;
			$payload['loose_files'] = isset( $resume['loose_files'] ) ? (int) $resume['loose_files'] : 0;
		} else {
			$top                    = self::read_top_level( $base );
			$pending                = $top['dirs'];
			$payload['loose_bytes'] = $top['loose_bytes'];
			$payload['loose_files'] = $top['loose_files'];
		}

		while ( ! empty( $pending ) ) {
			$name     = array_shift( $pending );
			$measured = self::measure_dir( $base . DIRECTORY_SEPARATOR . $name );

			$payload['subdirs'][] = [
				'name'  => $name,
				'bytes' => $measured['bytes'],
				'files' => $measured['files'],
			];

			// Budget is checked AFTER a whole subdirectory, so every call completes at
			// least one unit of work — a zero/negative budget yields a partial, never a
			// no-progress loop.
			if ( ! empty( $pending ) && self::should_exit( $started, $budget ) ) {
				$payload['done']   = false;
				$payload['cursor'] = [
					'pending'     => array_values( $pending ),
					'subdirs'     => $payload['subdirs'],
					'loose_bytes' => $payload['loose_bytes'],
					'loose_files' => $payload['loose_files'],
				];
				break;
			}
		}

		$payload['total_bytes'] = $payload['loose_bytes'];
		$payload['total_files'] = $payload['loose_files'];
		foreach ( $payload['subdirs'] as $entry ) {
			$payload['total_bytes'] += (int) $entry['bytes'];
			$payload['total_files'] += (int) $entry['files'];
		}

		// Largest first — the UI's proportional bars read top-down.
		usort(
			$payload['subdirs'],
			function ( $a, $b ) {
				if ( $a['bytes'] === $b['bytes'] ) {
					return strcmp( $a['name'], $b['name'] );
				}
				return ( $a['bytes'] < $b['bytes'] ) ? 1 : -1;
			}
		);

		return $payload;
	}

	/**
	 * List the base directory's immediate children.
	 *
	 * Directories become scan units; files are aggregated as "loose"; symlinks (to either)
	 * are skipped entirely — they are not our bytes and must not be walked.
	 *
	 * @param string $base Absolute directory path.
	 * @return array { dirs: string[], loose_bytes: int, loose_files: int }
	 */
	private static function read_top_level( $base ) {
		$top = [
			'dirs'        => [],
			'loose_bytes' => 0,
			'loose_files' => 0,
		];

		try {
			$iterator = new FilesystemIterator(
				$base,
				FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
			);
		} catch ( UnexpectedValueException $e ) {
			return $top; // Unreadable base — tolerated.
		}

		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) {
				continue;
			}

			if ( $item->isDir() ) {
				$top['dirs'][] = $item->getFilename();
				continue;
			}

			if ( $item->isFile() ) {
				$top['loose_bytes'] += self::safe_size( $item );
				$top['loose_files']++;
			}
		}

		sort( $top['dirs'] );

		return $top;
	}

	/**
	 * Build a symlink-safe, unreadable-tolerant recursive iterator.
	 *
	 * `SKIP_DOTS` keeps `.`/`..` out; `CATCH_GET_CHILD` swallows the
	 * UnexpectedValueException an unreadable SUBdirectory throws mid-walk (an unreadable
	 * TOP directory throws from the constructor, caught here → null). Symlinked
	 * directories are not descended: `RecursiveDirectoryIterator::hasChildren()` defaults
	 * to `$allow_links = false`.
	 *
	 * @param string $dir  Absolute directory path.
	 * @param int    $mode RecursiveIteratorIterator mode constant.
	 * @return RecursiveIteratorIterator|null
	 */
	public static function make_recursive_iterator( $dir, $mode ) {
		try {
			$directory = new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS );

			return new RecursiveIteratorIterator(
				$directory,
				$mode,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
		} catch ( UnexpectedValueException $e ) {
			return null;
		}
	}

	/**
	 * File size that never throws (a file can vanish, or stat can fail, mid-walk).
	 *
	 * @param \SplFileInfo $item File info.
	 * @return int
	 */
	public static function safe_size( $item ) {
		try {
			return (int) $item->getSize();
		} catch ( \RuntimeException $e ) {
			return 0;
		}
	}

	/**
	 * Whether a path is absolute (POSIX `/…`, Windows `C:\…` or a UNC `\\…` prefix).
	 *
	 * @param string $path Path to test.
	 * @return bool
	 */
	private static function is_absolute_path( $path ) {
		if ( '' === $path ) {
			return false;
		}

		if ( '/' === $path[0] || '\\' === $path[0] ) {
			return true;
		}

		return (bool) preg_match( '#^[a-zA-Z]:[\\\\/]#', $path );
	}
}
