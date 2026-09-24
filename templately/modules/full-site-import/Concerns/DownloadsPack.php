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
use Templately\Utils\Response\ResponseNormalizer;
use Templately\Utils\Options;

/**
 * DownloadsPack — extracted verbatim from FullSiteImport (behavior-preserving).
 * Composed back into FullSiteImport via `use`; $this and method resolution unchanged.
 */
trait DownloadsPack {
	/**
	 * @throws Exception
	 */
	private function check_writing_permission() {
		$upload_dir = wp_upload_dir();

		if (!is_writable($upload_dir['basedir'])) {
			$this->throw(__('Upload directory is not writable.', 'templately'));
		}

		// Goes through Helper so the wp-uploads/templately root gets its
		// index.php / .htaccess / web.config guards before anything is written
		// into it — the extracted pack lives here and uploads is web-served.
		$this->tmp_dir = Helper::upload_dir('tmp');

		if (!is_dir($this->tmp_dir)) {
			wp_mkdir_p($this->tmp_dir);
		}

		$this->sse_log('writing_permission_check', __('Permission Passed', 'templately'), 100);
	}

	/**
	 * @throws Exception
	 */
	private function download_zip( $id, $is_ai = false ) {
		$this->sse_log( 'download', __( 'Downloading Template Pack', 'templately' ), 1 );
		$extra_headers = [
			// Same normalization as Utils\PackInfoFetcher::fetch() — a raw PHP boolean
			// stringifies to "1"/"" when WP's HTTP layer serializes the header, which the
			// upstream API does not recognize as true/false. Send the literal string.
			'x-templately-is-ai'              => rest_sanitize_boolean( $is_ai ) ? 'true' : 'false',
			'x-templately-session-id'         => $this->session_id,
			'x-templately-requested-platform' => Helper::get_requested_platform(),
		];
		// A run that stops in this method used to leave ONE "Downloading Template Pack"
		// line and nothing else — no status, no size, no timing, no way to tell a refused
		// download from a request that simply ended. Everything below narrates it.
		Helper::log(
			sprintf( 'download_zip: requesting pack %s (session=%s, is_ai=%s)', $id, $this->session_id, $is_ai ? 'yes' : 'no' ),
			'download_zip',
			'info'
		);

		// The decisive instrument. `register_shutdown_function` runs on a fatal AND on a
		// client disconnect, so if the request ends before the pack reaches disk this says
		// WHICH: `connection_aborted=1` means the browser hung up (PHP has
		// ignore_user_abort=0, so it stops at the next write, silently and with no error),
		// and a non-null `last_error` means PHP died.
		$download_started = microtime( true );
		$session_id       = $this->session_id;
		register_shutdown_function( function () use ( $id, $session_id, $download_started ) {
			if ( defined( 'TEMPLATELY_PACK_SAVED' ) ) {
				return;
			}

			Helper::log(
				sprintf(
					'download_zip: request ENDED before the pack was saved after %.1fs (pack=%s, session=%s, connection_aborted=%d, last_error=%s)',
					microtime( true ) - $download_started,
					$id,
					$session_id,
					connection_aborted(),
					wp_json_encode( error_get_last() )
				),
				'download_zip',
				'error'
			);
		} );

		$response = Helper::make_api_get_request("v2/import/pack/$id", [], $extra_headers, 90);

		Helper::log(
			sprintf(
				'download_zip: pack %s answered %s in %.1fs (%s bytes, type=%s, download-key=%s)',
				$id,
				is_wp_error( $response ) ? 'WP_Error: ' . $response->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ),
				microtime( true ) - $download_started,
				is_wp_error( $response ) ? '0' : strlen( (string) wp_remote_retrieve_body( $response ) ),
				is_wp_error( $response ) ? '-' : (string) wp_remote_retrieve_header( $response, 'content-type' ),
				wp_remote_retrieve_header( $response, 'download-key' ) ? 'present' : 'missing'
			),
			'download_zip',
			is_wp_error( $response ) ? 'error' : 'info'
		);

		// The pack is a ZIP, so the RAW response is kept: the body is bytes to write
		// to disk and `download-key` is a header the session needs. FR-012 — binary
		// payloads pass through the normalizer untouched — so the normalizer is used
		// only to CLASSIFY, never to reshape what gets written.
		$this->download_key = wp_remote_retrieve_header($response, 'download-key');

		$normalized = ResponseNormalizer::normalize($response, [
			'raw'          => true,
			'side_effects' => false,
		]);

		if ($normalized->is_error()) {
			$error = $normalized->error();

			Helper::log(
				sprintf( 'download_zip: refused — %s: %s (retryable=%s)', $error->code(), wp_strip_all_tags( $error->message() ), $error->is_retryable() ? 'yes' : 'no' ),
				'download_zip',
				'error'
			);

			// Retryability now comes from the registry rather than from guessing at
			// the transport: a dropped connection or a 5xx is worth another attempt,
			// an expired session or a missing pack is not.
			if ($error->is_retryable()) {
				$this->throw_retryable(__('Template pack download failed. ', 'templately') . $error->message());
			}

			$support_message = '';
			if (strpos($error->message(), Helper::web_url( '', [ 'support' => 'open' ] )) === false) {
				$support_message = sprintf(__(" Please try again or contact <a href='%s' target='_blank'>support</a>.", "templately"), Helper::web_url( '', [ 'support' => 'open' ] ));
			}

			$this->throw_non_retryable($error->message() . $support_message);
		}

		$this->sse_log('download', __('Downloading Template Pack', 'templately'), 57);

		SessionData::set($this->session_id, 'download_key', $this->download_key);

		// Security: Validate file path is within WordPress upload directory before writing
		$validation = AIUtils::validate_file_path($this->filePath);
		if (is_wp_error($validation)) {
			$this->throw($validation->get_error_message());
		}

		wp_mkdir_p(dirname($this->filePath));

		// A full disk is not transient: a retry loop against it never resolves.
		// Require the zip size plus headroom for extraction (packs expand).
		$needed = strlen($response['body']) * 3;
		$free   = @disk_free_space(dirname($this->filePath)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- some hosts disable the function; false skips the check.
		if (false !== $free && $free < $needed) {
			$this->throw(__('Not enough disk space to import this pack. Please free up space and try again.', 'templately'));
		}

		$written = file_put_contents($this->filePath, $response['body']); // phpcs:ignore

		Helper::log(
			sprintf( 'download_zip: wrote %s bytes to %s', var_export( $written, true ), $this->filePath ),
			'download_zip',
			false === $written ? 'error' : 'info'
		);

		if ($written) {
			// Past this point the shutdown notice above would be a false alarm.
			if ( ! defined( 'TEMPLATELY_PACK_SAVED' ) ) {
				define( 'TEMPLATELY_PACK_SAVED', true );
			}

			$this->sse_log('download', __('Downloading Template Pack', 'templately'), 100);

			$this->unzip();

			Helper::log( sprintf( 'download_zip: unzipped into %s', $this->dir_path ?? '(unset)' ), 'download_zip', 'info' );
		} else {
			$this->throw_retryable(__('Downloading Failed. Please try again', 'templately'));
		}
	}

	/**
	 * @throws Exception
	 */
	protected function unzip() {
		if (!WP_Filesystem()) {
			$this->throw(__('WP_Filesystem cannot be initialized', 'templately'));
		}
		$unzip = unzip_file($this->filePath, $this->dir_path);
		if (is_wp_error($unzip)) {
			// Fall back to our own ZipArchive-based extractor. Some Templately
			// packs carry entries with a leading "./" (or embedded "/./") path
			// segment, which WordPress core's unzip_file() fails to extract.
			// self::unzip_file() extracts with native ZipArchive and normalizes
			// those "./" segments so the pack still imports. See
			// self::unzip_file() for the extraction and path-traversal guard.
			$unzip = $this->unzip_file($this->filePath, $this->dir_path);
		}

		if (is_wp_error($unzip)) {
			// Both extractors failed. Previously this result was dropped and the
			// import limped on to a misleading "manifest is corrupted" error.
			if ('zip_extract_write_failed' === $unzip->get_error_code()) {
				// Disk full / not writable — retrying cannot fix it.
				$this->throw($unzip->get_error_message());
			}
			// A corrupt/truncated archive is often a transient download problem —
			// a retry re-downloads the zip.
			$this->throw_retryable($unzip->get_error_message());
		}

		// Core's unzip_file() extracts whatever the archive holds, and the pack
		// lands under web-served wp-uploads. Sweep before anything else touches
		// the tree, so the relocation below cannot copy an executable upward.
		$this->purge_disallowed_files($this->dir_path);

		$manifest_file = $this->dir_path . 'manifest.json';

		// If manifest.json is missing, but any subdirectory contains manifest.json, move all its contents up and remove the subdirectory.
		if ( ! file_exists( $manifest_file ) ) {
			$entries = array_diff( scandir( $this->dir_path ), [ '.', '..' ] );
			// Plain closures, not arrow functions: this file must parse on the
			// advertised PHP 7.2 floor (`Requires PHP` in readme.txt), and `fn()`
			// is 7.4+. `$this` is bound in a closure declared inside an instance
			// method exactly as it is in an arrow function, so `$this->dir_path`
			// resolves identically and no `use` clause is needed.
			$dirs = array_filter( $entries, function( $e ) {
				return is_dir( $this->dir_path . $e );
			} );
			$files = array_filter( $entries, function( $e ) {
				return is_file( $this->dir_path . $e );
			} );
			foreach ($dirs as $subdir) {
				$subdir_path = $this->dir_path . $subdir . DIRECTORY_SEPARATOR;
				if ( file_exists( $subdir_path . 'manifest.json' ) ) {
					copy($subdir_path . 'manifest.json', $manifest_file);

					foreach ( array_diff( scandir( $subdir_path ), [ '.', '..' ] ) as $item ) {
						$src = $subdir_path . $item;
						$dst = $this->dir_path . $item;
						if (is_dir($src)) {
							if (!file_exists($dst)) {
								wp_mkdir_p($dst);
							}
							// Recursively copy directory
							$this->copyDirectory($src, $dst);
						} else {
							copy($src, $dst);
						}
					}
					// Remove the subdirectory and its contents
					$this->removeDirectory($subdir_path);
					break; // Only process the first subdir with manifest.json
				}
			}
		}

		if (is_wp_error($unzip)) {
			$error = $unzip->get_error_message();
			if (empty($error)) {
				// Generic error message
				Helper::log($unzip);
				$error_message = sprintf(__("It seems we're experiencing technical difficulties. Please try again or contact <a href='%s' target='_blank'>support</a>.", "templately"), Helper::web_url( '', [ 'support' => 'open' ] ));
				$this->throw($error_message);
			} else {
				$this->throw($unzip->get_error_message());
			}
		}

		if ($unzip) {
			unlink($this->filePath);
		}
	}

	/**
	 * Recursively copy a directory
	 */
	private function copyDirectory($src, $dst) {
		$dir = opendir($src);
		wp_mkdir_p($dst);
		while(false !== ($file = readdir($dir))) {
			if (($file != '.') && ($file != '..')) {
				if (is_dir($src . DIRECTORY_SEPARATOR . $file)) {
					$this->copyDirectory($src . DIRECTORY_SEPARATOR . $file, $dst . DIRECTORY_SEPARATOR . $file);
				} else {
					copy($src . DIRECTORY_SEPARATOR . $file, $dst . DIRECTORY_SEPARATOR . $file);
				}
			}
		}
		closedir($dir);
	}

	/**
	 * Recursively remove a directory
	 */
	private function removeDirectory($dir) {
		if (!file_exists($dir)) return;
		$items = array_diff(scandir($dir), ['.', '..']);
		foreach ($items as $item) {
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if (is_dir($path)) {
				$this->removeDirectory($path);
			} else {
				unlink($path);
			}
		}
		rmdir($dir);
	}

	/**
	 * Unzip a specified ZIP file to a location on the Filesystem.
	 *
	 * Why this custom extractor exists: some Templately packs carry entries with
	 * a leading "./" (or embedded "/./") path segment, which WordPress core's
	 * unzip_file() fails to extract. This method extracts with PHP's native
	 * ZipArchive and normalizes the redundant "./" segments (see
	 * normalize_zip_entry_name()) so the pack still imports. It is a fallback:
	 * self::unzip() only calls it after the core unzip_file() returns a WP_Error.
	 *
	 * Each archive member is validated before extraction rather than
	 * calling ZipArchive::extractTo() blindly: entries that resolve outside the
	 * destination (path traversal / "Zip Slip"), absolute paths, and Windows
	 * drive-letter paths are skipped via validate_file(), mirroring the guard
	 * WordPress core applies in _unzip_file_ziparchive(). Redundant "./" path
	 * segments are normalized first so members land at their intended location.
	 *
	 * @param string $file Full path and filename of ZIP archive.
	 * @param string $to Full path on the filesystem to extract archive to.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	/**
	 * Extensions an extracted pack member is permitted to use.
	 *
	 * Deliberately an allowlist, and deliberately WordPress's own: naming the
	 * dangerous extensions instead means being exhaustive about `.phtml`, `.pht`,
	 * `.phar`, `.cgi`, `.htaccess`, `.user.ini` and whatever a future server
	 * config decides to execute — one omission and the guard is silent.
	 * get_allowed_mime_types() already encodes what this site accepts, follows
	 * the `upload_mimes` filter, and gains new formats as WordPress does.
	 *
	 * @return array Extension => true lookup.
	 */
	protected static function allowed_pack_extensions() {
		$allowed = array_fill_keys(self::PACK_EXTENSIONS, true);

		// Keys are alternation patterns: 'jpg|jpeg|jpe' => 'image/jpeg'.
		foreach (array_keys(get_allowed_mime_types()) as $pattern) {
			foreach (explode('|', $pattern) as $extension) {
				$allowed[$extension] = true;
			}
		}

		return $allowed;
	}

	/**
	 * Segments that must never appear anywhere in a pack member's name.
	 *
	 * This one IS a denylist, and only because it is applied to the segments
	 * *before* the real extension, which the allowlist has already vetted. A
	 * permissive `AddHandler` runs `shell.php.gif` as PHP, so an inner segment
	 * still has to be refused — but refusing every inner segment the allowlist
	 * does not know would delete ordinary names like `style.min.css` or
	 * `hero.2x.png`, where the inner segment is a word, not an extension.
	 *
	 * An omission here is far less serious than in the allowlist: it only
	 * matters for a name whose final extension is already an accepted upload
	 * type, on a server configured to hand an inner extension to a handler.
	 *
	 * A METHOD, not a `const`: this is a trait, and constants in traits are a
	 * PHP 8.2 feature — declaring one here is a parse-time fatal on every host
	 * below that, which is most of the supported range. `latest` carries the
	 * same list as `FullSiteImport::EXECUTABLE_SEGMENTS` because there it sits
	 * on a class.
	 *
	 * @return array Segment => true lookup.
	 */
	protected static function executable_segments() {
		return array_fill_keys( [
			'php', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8',
			'phps', 'phtml', 'phtm', 'pht', 'phar', 'inc',
			'cgi', 'fcgi', 'pl', 'py', 'rb', 'sh', 'bash', 'ksh', 'csh', 'zsh',
			'asp', 'aspx', 'ascx', 'ashx', 'asmx', 'cfm', 'cfml',
			'jsp', 'jspx', 'jar', 'war',
			'shtml', 'shtm',
			'exe', 'com', 'bat', 'cmd', 'dll', 'so',
			'htaccess', 'htpasswd', 'ini', 'env', 'conf',
		], true );
	}

	/**
	 * Whether an archive entry (or extracted file) is something a pack may hold.
	 *
	 * Two different rules, because a filename's dots do not all mean the same
	 * thing. The LAST segment is the extension the server dispatches on, so it
	 * is checked against the allowlist. The segments before it are usually just
	 * part of the name — `style.min.css`, `hero.2x.png`, `logo.v2.png`,
	 * `12.ai.json` — so they are only checked against EXECUTABLE_SEGMENTS,
	 * which is what still refuses `shell.php.gif` under a permissive
	 * `AddHandler`.
	 *
	 * A name with no extension at all is refused, which is what catches
	 * `.htaccess`, `.user.ini` and `.env` — none of them have one.
	 *
	 * @param string $name    Entry name or file path.
	 * @param array  $allowed Lookup from allowed_pack_extensions(). Built on
	 *                        demand when omitted; pass it in when looping.
	 *
	 * @return bool
	 */
	protected static function is_allowed_entry($name, $allowed = null) {
		if (null === $allowed) {
			$allowed = self::allowed_pack_extensions();
		}

		$segments = explode('.', strtolower(wp_basename($name)));

		// No extension, or a leading-dot name whose only "segment" is empty.
		if (count($segments) < 2 || '' === $segments[0]) {
			return false;
		}

		$extension = array_pop($segments);
		if (!isset($allowed[$extension])) {
			return false;
		}

		$executable = self::executable_segments();

		// array_slice() drops the base name; only what sits between it and the
		// extension is a masking risk.
		foreach (array_slice($segments, 1) as $segment) {
			if (isset($executable[$segment])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Deletes anything an extracted pack has no business containing.
	 *
	 * The fallback extractor below refuses these entries outright, but WordPress
	 * core's unzip_file() runs first and has no such filter, so the guard has to
	 * exist on the extracted tree as well.
	 *
	 * @param string $dir Extracted pack root.
	 *
	 * @return int Number of files removed.
	 */
	protected function purge_disallowed_files($dir) {
		if (empty($dir) || !is_dir($dir)) {
			return 0;
		}

		$removed = 0;
		$allowed = self::allowed_pack_extensions();

		try {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ($files as $fileinfo) {
				if (!$fileinfo->isFile() || self::is_allowed_entry($fileinfo->getFilename(), $allowed)) {
					continue;
				}

				if (@unlink($fileinfo->getPathname())) {
					$removed++;
					Helper::log($fileinfo->getPathname(), 'unzip: removed disallowed file from extracted pack', 'warning');
				}
			}
		} catch (\Exception $e) {
			Helper::log($e->getMessage(), 'purge_disallowed_files', 'warning');
		}

		return $removed;
	}

	function unzip_file($file, $to) {
		$zip = new \ZipArchive;

		$res = $zip->open($file);
		if ($res !== TRUE) {
			// Report the open() RETURN CODE, never the handle. Reading
			// $zip->status / $zip->getStatusString() here interrogates an
			// archive that was never opened: ext-zip before PHP 8.0 answers
			// that with "Invalid or uninitialized Zip object" — a warning
			// raised inside the advertised PHP range (readme floor 7.2), on
			// the very path that reports a corrupt download.
			return new \WP_Error('zip_error_' . $res, sprintf(
				/* translators: %d: PHP ZipArchive::open() error code. */
				__('Could not open the downloaded archive (ZipArchive error code %d).', 'templately'),
				$res
			));
		}

		// Close the archive handle on every exit path (success, mid-loop
		// exception, or early return) so it is never leaked.
		try {
			$to = trailingslashit($to);

			$allowed_extensions = self::allowed_pack_extensions();

			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = $zip->getNameIndex($i);
				if ($name === false) {
					continue;
				}

				// Normalize redundant "./" segments so the destination path
				// is computed from a clean entry name.
				$name = $this->normalize_zip_entry_name($name);
				if ($name === '') {
					continue; // Archive root (e.g. a "./" entry).
				}

				// Skip the OS X-created __MACOSX directory.
				if (strpos($name, '__MACOSX/') === 0) {
					continue;
				}

				// Don't extract invalid files: reject "../" traversal,
				// absolute, and drive-letter paths so no member can be
				// written outside $to. Log the skipped entry name so a
				// hostile or corrupt pack leaves a forensic trail rather
				// than silently extracting only part of its contents.
				if (0 !== validate_file($name)) {
					Helper::log($name, 'unzip_file: skipped unsafe archive entry', 'warning');
					continue;
				}

				// validate_file() stops a member escaping $to; it says nothing
				// about what the member *is*. $to lives under web-served
				// wp-uploads, so an executable member would be directly
				// requestable.
				if (substr($name, -1) !== '/' && !self::is_allowed_entry($name, $allowed_extensions)) {
					Helper::log($name, 'unzip_file: skipped disallowed archive entry', 'warning');
					continue;
				}

				if (substr($name, -1) === '/') {
					// Directory entry.
					wp_mkdir_p($to . untrailingslashit($name));
					continue;
				}

				$contents = $zip->getFromIndex($i);
				if ($contents === false) {
					// An unreadable member means a corrupt archive. Fail HERE —
					// silently continuing produced a partial extract whose
					// missing/truncated JSON only surfaced runners later as an
					// unrelated "corrupted" error.
					return new \WP_Error(
						'zip_member_unreadable',
						sprintf(
							/* translators: %s: archive entry name */
							__('The template pack archive is corrupted (unreadable entry: %s). Please try again.', 'templately'),
							$name
						)
					);
				}

				$target = $to . $name;
				wp_mkdir_p(dirname($target));
				$written = file_put_contents($target, $contents); // phpcs:ignore
				if (false === $written || $written < strlen($contents)) {
					// Disk full / permissions mid-extraction: fail loudly now,
					// not later when a runner reads the truncated file.
					return new \WP_Error(
						'zip_extract_write_failed',
						__('Could not write the template pack to disk (disk full or not writable). Please free up space and try again.', 'templately')
					);
				}
			}

			return true;
		} catch (\Throwable $th) {
			return new \WP_Error('exception_caught', $th->getMessage());
		} finally {
			$zip->close();
		}
	}

	/**
	 * Removes redundant current-directory ("./") segments from a ZIP entry path.
	 *
	 * Some archive tools store entry names with a leading "./" or embedded "/./"
	 * segment (for example "./manifest.json" or "content/./page.json"). Parent
	 * ("..") segments are intentionally left untouched so validate_file() can
	 * still reject them.
	 *
	 * @param string $name A ZIP archive entry path.
	 * @return string The entry path with current-directory segments removed.
	 */
	protected function normalize_zip_entry_name($name) {
		// Fast path: bail when there is no current-directory segment to remove.
		if ('.' !== $name
			&& strpos($name, './') !== 0
			&& strpos($name, '/./') === false
			&& substr($name, -2) !== '/.'
		) {
			return $name;
		}

		// A trailing slash, or a trailing "/." or bare ".", denotes a directory.
		$is_directory = substr($name, -1) === '/' || substr($name, -2) === '/.' || '.' === $name;

		$segments = array();
		foreach (explode('/', $name) as $segment) {
			if ('.' !== $segment) {
				$segments[] = $segment;
			}
		}

		$name = implode('/', $segments);

		// Preserve the trailing slash that marks a directory entry.
		if ($is_directory && '' !== $name && substr($name, -1) !== '/') {
			$name .= '/';
		}

		return $name;
	}

	/**
	 * @throws Exception
	 */
	private function read_manifest($dir_path) {
		$manifest_content = file_get_contents($dir_path . 'manifest.json');
		if (empty($manifest_content)) {
			$this->throw(__('Cannot be imported, as the manifest file is corrupted', 'templately'));
		}

		$manifest_content = json_decode($manifest_content, true);
		$this->removeLog('temp');

		return $manifest_content;
		// TODO: Read & Broadcast the LOG for waiting list
		// $this->sse_log( 'plugin', 'Installing required plugins', '--', 'updateLog', 'processing' );
		// // $this->sse_log( 'extra-content', 'Import Extra Contents (i.e: Forms)', '--', 'updateLog', 'processing' );
		// $this->sse_log( 'templates', 'Import Templates (i.e: Header, Footer etc)', '--', 'updateLog', 'processing' );
		// // $this->sse_log( 'content', 'Import Pages, Posts etc', '--', 'updateLog', 'processing' );
		// $this->sse_log( 'wp-content', 'Importing Pages, Posts, Navigation, etc', '--', 'updateLog', 'processing' );
		// $this->sse_log( 'finalize', 'Finalizing Your Imports', '--', 'updateLog', 'processing' );
	}

}
