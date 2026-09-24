<?php
/**
 * Is this actually an installable WordPress plugin archive?
 *
 * The download service's response shape is UNKNOWN and cannot be probed — the qualifying
 * subscription plans do not exist on the cloud yet, and this repository forbids firing
 * speculative requests at production to find out. So nothing here parses. It VALIDATES:
 * one shape is accepted and everything else is refused, which is correct whatever the
 * service turns out to answer with, and which is what lets the live source replace the
 * mock without a single test changing (spec 059 FR-023).
 *
 * The bytes are untrusted third-party input. They are inspected in the temp directory and
 * never extracted here — `Plugin_Upgrader` does the installation, because it is
 * WordPress's own vetted path and it is the one that knows about filesystem credentials,
 * ownership, and rollback.
 *
 * @package Templately\Modules\ProPluginProvisioning
 */

namespace Templately\Modules\ProPluginProvisioning;

use ZipArchive;

class Archive {

	/**
	 * Whether a file on disk is a ZIP containing at least one WordPress plugin.
	 *
	 * A JSON error body, an HTML error page, an empty response, a truncated download, a
	 * ZIP of something that is not a plugin — all of them fail here, and all of them mean
	 * the same thing to the caller: not available.
	 *
	 * When `$expected_dir` is given, the headed file must ALSO sit in exactly that folder.
	 * The service can hand back a different product than the one asked for — a
	 * `platform=elementor` request has been observed answering with Essential Blocks Pro
	 * (2026-09-08) — and "a valid plugin" is not the same question as "the plugin the
	 * catalog pinned". Without this the wrong licensed plugin installs silently.
	 *
	 * @param string      $path         Absolute path to a downloaded file.
	 * @param string|null $expected_dir Plugin folder the archive must contain, or null to accept any.
	 * @return bool
	 */
	public static function is_installable_plugin( $path, $expected_dir = null ): bool {
		if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
			return false;
		}

		if ( filesize( $path ) < 100 ) {
			// Smaller than any real plugin zip; almost certainly an error body.
			return false;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			// Without the extension we cannot inspect the archive, and installing an
			// unverified one is not an acceptable substitute for checking.
			return false;
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $path ) ) {
			return false;
		}

		$has_plugin_header = false;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );

			if ( ! is_string( $name ) || substr( $name, -4 ) !== '.php' ) {
				continue;
			}

			// Only `<folder>/<file>.php`. WordPress's own `Plugin_Upgrader::check_package()`
			// looks for the header at the top level of the extracted folder and rejects an
			// archive whose only headed file is nested deeper — so accepting one here would
			// pass the validator and still hard-fail the install.
			$parts = explode( '/', trim( $name, '/' ) );

			if ( 2 !== count( $parts ) ) {
				continue;
			}

			// Right shape, wrong product — see the docblock.
			if ( is_string( $expected_dir ) && '' !== $expected_dir && $parts[0] !== $expected_dir ) {
				continue;
			}

			// Plugin headers must be within the first 8KB for WordPress to read them, so
			// there is no reason to pull a whole file into memory to look for one.
			$contents = $zip->getFromIndex( $i, 8192 );

			if ( is_string( $contents ) && preg_match( '/^[ \t\/*#@]*Plugin Name:\s*\S/mi', $contents ) ) {
				$has_plugin_header = true;
				break;
			}
		}

		$zip->close();

		return $has_plugin_header;
	}

	/**
	 * Delete a transient archive, ignoring a file that is already gone.
	 *
	 * Nothing licensed may survive the request that downloaded it (FR-024), so this is
	 * called on BOTH the success and the failure branch of installation.
	 *
	 * @param string|null $path
	 */
	public static function discard( $path ): void {
		if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a vanished temp file is not an error.
		}
	}
}
