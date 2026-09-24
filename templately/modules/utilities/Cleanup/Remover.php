<?php
/**
 * The ONE path through which anything is ever deleted.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup;

use RecursiveIteratorIterator;
use SplFileInfo;
use WP_Error;

/**
 * No cleanup task holds a deletion capability of its own. Every removal — from
 * every task, including ones written later by anyone — comes through here, so
 * the safety rules have exactly one implementation and cannot be forgotten by a
 * contributor who did not read the interface docs.
 *
 * `Scanner` deliberately no longer exposes `delete_tree()`. It measures and it
 * validates paths; it does not delete. That is what makes "exactly one
 * implementation of the safety rules" a structural fact rather than a
 * convention — a task cannot reach a raw recursive delete even by accident.
 *
 * Four rules, enforced here for everyone:
 *
 *  1. CONTAINMENT — the resolved real path must sit inside the plugin's own
 *     uploads root. The check uses a trailing separator, so a sibling directory
 *     sharing a name prefix (`/uploads/templately-evil`) is refused rather than
 *     passing a naive `strpos()` test.
 *  2. SYMLINKS — never followed. A link is unlinked as a link; its target is
 *     never traversed and never removed.
 *  3. GUARD FILES — `.htaccess`, `index.php`, `index.html` and `web.config` are
 *     immortal, anywhere under the root. These are what keep a web-reachable
 *     uploads directory private, they are written once and therefore always the
 *     oldest thing present, and deleting them was a real shipped defect (see
 *     spec 025 FR-015). `web.config` is the IIS equivalent of the `.htaccess`
 *     deny rule, written by `Helper::protect_directory()`; it has to be on this
 *     list for the same reason the other three are, and it is easy to miss
 *     because Apache sites never see one.
 *  4. LIVE FILES — a caller may declare paths that are being written to right
 *     now; they are never candidates.
 */
final class Remover {

	/**
	 * Filenames no task may ever remove.
	 *
	 * Not configurable. A contributor who thinks they need to delete one of
	 * these is wrong: they exist to keep the directory unreadable from the web.
	 */
	const GUARD_FILES = [ '.htaccess', 'index.php', 'index.html', 'web.config' ];

	/** @var string[] Absolute paths excluded for this request (live logs, etc.). */
	private static $protected = [];

	/**
	 * Protect paths that are in use right now — a log being appended to, the
	 * working directory of a running import.
	 *
	 * @param string[] $paths Absolute paths.
	 */
	public static function protect( array $paths ): void {
		foreach ( $paths as $path ) {
			$real = realpath( $path );
			if ( false !== $real ) {
				self::$protected[ $real ] = true;
			}
		}
	}

	/**
	 * Test seam.
	 */
	public static function reset_protected(): void {
		self::$protected = [];
	}

	/**
	 * Remove a directory and everything inside it.
	 *
	 * @param string  $target    Absolute path, or one relative to the uploads root.
	 * @param Context $context   Honours `dry_run` — nothing is touched when set.
	 * @param bool    $keep_root Empty the directory but keep the directory itself.
	 * @return TaskResult|WP_Error Bytes/items, or a refusal explaining why.
	 */
	public static function delete_directory( string $target, Context $context, bool $keep_root = false ) {
		$resolved = Scanner::resolve_target( $target );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		// Refusing the root outright: emptying the whole uploads tree is never
		// what an individual task means, and a task that computed its way to ''
		// has a bug we should surface rather than act on.
		if ( $resolved['is_root'] && ! $keep_root ) {
			return new WP_Error(
				'refuse_root_delete',
				__( 'Refusing to remove the Templately uploads root.', 'templately' ),
				[ 'status' => 400 ]
			);
		}

		if ( isset( self::$protected[ $resolved['path'] ] ) ) {
			return new WP_Error(
				'target_in_use',
				__( 'That directory is in use.', 'templately' ),
				[ 'status' => 409 ]
			);
		}

		if ( $context->is_dry_run() ) {
			$measured = Scanner::measure_dir( $resolved['path'] );

			return TaskResult::empty()->add( (int) $measured['files'], (int) $measured['bytes'] );
		}

		return self::delete_tree( $resolved['path'], $keep_root );
	}

	/**
	 * Remove a single file.
	 *
	 * @param string  $path    Absolute path, or one relative to the uploads root.
	 * @param Context $context Honours `dry_run`.
	 * @return TaskResult|WP_Error
	 */
	public static function delete_file( string $path, Context $context ) {
		$base = realpath( Scanner::get_base_dir() );
		if ( false === $base ) {
			return new WP_Error(
				'uploads_base_missing',
				__( 'The Templately uploads directory does not exist.', 'templately' ),
				[ 'status' => 404 ]
			);
		}
		$base = rtrim( $base, '/\\' );

		$real = realpath( $path );
		if ( false === $real ) {
			// Already gone. Not an error — a task that raced another sweep, or a
			// record whose file was removed by hand, should not report a failure.
			return TaskResult::empty();
		}

		// Same strict containment as directories: the trailing separator is what
		// stops `/uploads/templately-evil/x.log` passing as ours.
		if ( 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
			return new WP_Error(
				'target_outside_base',
				__( 'That path is outside the Templately uploads directory.', 'templately' ),
				[ 'status' => 400 ]
			);
		}

		if ( self::is_guard_file( $real ) ) {
			return new WP_Error(
				'refuse_guard_file',
				__( 'That file protects the uploads directory and cannot be removed.', 'templately' ),
				[ 'status' => 400 ]
			);
		}

		if ( isset( self::$protected[ $real ] ) ) {
			return new WP_Error(
				'target_in_use',
				__( 'That file is in use.', 'templately' ),
				[ 'status' => 409 ]
			);
		}

		if ( is_dir( $real ) && ! is_link( $real ) ) {
			return new WP_Error(
				'target_is_a_directory',
				__( 'That path is a directory.', 'templately' ),
				[ 'status' => 400 ]
			);
		}

		$size = (int) @filesize( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( $context->is_dry_run() ) {
			return TaskResult::empty()->add( 1, $size );
		}

		if ( ! @unlink( $real ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return TaskResult::empty()->fail( sprintf( 'Could not remove %s', basename( $real ) ) );
		}

		return TaskResult::empty()->add( 1, $size );
	}

	/**
	 * Whether a path is one of the directory's protective files.
	 */
	public static function is_guard_file( string $path ): bool {
		return in_array( basename( $path ), self::GUARD_FILES, true );
	}

	/**
	 * The recursive removal itself. PRIVATE — this is the capability the whole
	 * class exists to keep out of task authors' hands.
	 *
	 * Relocated verbatim from the developer-only uploads scanner, which is why
	 * its symlink and failure-tolerance behaviour is unchanged: children are
	 * visited CHILD_FIRST so directories are empty by the time they are removed,
	 * a symlink is unlinked as a link and never followed, and a file that cannot
	 * be removed is simply not counted rather than aborting a partial delete.
	 *
	 * Guard files are skipped here too, not only in `delete_file()` — a
	 * directory delete must never take the root's guards with it.
	 */
	private static function delete_tree( string $path, bool $keep_root = false ): TaskResult {
		$result = TaskResult::empty();

		if ( ! is_dir( $path ) ) {
			return $result;
		}

		$iterator = Scanner::make_recursive_iterator( $path, RecursiveIteratorIterator::CHILD_FIRST );
		if ( null === $iterator ) {
			return $result->fail( sprintf( 'Could not read %s', basename( $path ) ) );
		}

		foreach ( $iterator as $item ) {
			/** @var SplFileInfo $item */
			$item_path = $item->getPathname();

			// isLink() FIRST: a symlink to a directory answers isDir() === true,
			// and we must remove the LINK, never walk into its target.
			if ( $item->isLink() ) {
				@unlink( $item_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				continue;
			}

			if ( $item->isDir() ) {
				if ( @rmdir( $item_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$result->add_dirs( 1 );
				}
				continue;
			}

			if ( $keep_root && self::is_guard_file( $item_path ) ) {
				// Emptying a directory we are keeping must not strip the guards
				// that keep it private.
				continue;
			}

			$size = Scanner::safe_size( $item );
			if ( @unlink( $item_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$result->add( 1, $size );
			}
		}

		if ( ! $keep_root && @rmdir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$result->add_dirs( 1 );
		}

		Scanner::bust_cache();

		return $result;
	}
}
