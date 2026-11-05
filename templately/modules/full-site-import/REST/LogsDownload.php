<?php

namespace Templately\Modules\FullSiteImport\REST;

use Templately\API\API;
use Templately\Utils\Log\LogFile;
use WP_Error;
use WP_REST_Request;
use ZipArchive;

/**
 * `GET /templately/v1/import/logs/download` — bundles every log a support
 * engineer needs for a failed import into one zip and streams it back.
 *
 * Contents:
 *  - `templately/`   every `*.log` in `uploads/templately/log/` (the plugin's
 *                    diagnostic log + its rotated generation, and the per-import
 *                    `fsi-*.log` SSE logs),
 *  - `wp-debug.log`  the tail of WordPress's `WP_DEBUG_LOG` file, when one exists,
 *  - `php-error.log` the tail of PHP's `error_log` ini target, when it differs
 *                    from the above and is readable (hosts often log fatals
 *                    here even with WP_DEBUG_LOG off),
 *  - `system-info.txt` versions, limits and active plugins.
 *
 * The developer HTTP inspector's `*.jsonl` capture is left out on purpose — it
 * records raw request headers, Authorization included.
 *
 * Dev option: nothing is registered unless `TEMPLATELY_LOG_DOWNLOAD` is
 * truthy (wp-config define, or the dev console's Constants panel).
 *
 * Admin-only (`manage_options`) and deliberately NOT gated on a connected
 * api_key: the site that most needs its logs may be the one that lost its
 * connection.
 */
class LogsDownload extends API {

	/**
	 * Files outside the Templately log dir can be huge (a years-old debug.log);
	 * only their tail is bundled.
	 */
	const TAIL_BYTES = 5242880;

	/**
	 * @return bool Whether the `TEMPLATELY_LOG_DOWNLOAD` dev option is on.
	 */
	public static function is_enabled() {
		return defined( 'TEMPLATELY_LOG_DOWNLOAD' ) && TEMPLATELY_LOG_DOWNLOAD;
	}

	public function _permission_check( WP_REST_Request $request ) {
		$this->request = $request;

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to download the Templately logs.', 'templately' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	public function register_routes() {
		$this->get( 'import/logs/download', [ $this, 'download' ] );
	}

	public function download() {
		// Anything that goes wrong comes back as a readable JSON error — a bare
		// 500 from a customer site tells support nothing.
		try {
			$entries = self::collect_entries();
			$tmp     = self::temp_file();

			if ( ! $tmp || ! self::write_zip( $tmp, $entries ) ) {
				@unlink( $tmp );
				return $this->error( 'zip_failed', __( 'Could not create the log archive.', 'templately' ), 'import/logs/download', 500 );
			}
		} catch ( \Throwable $e ) {
			return $this->error( 'logs_download_failed', sprintf( '%s: %s (%s:%d)', get_class( $e ), $e->getMessage(), basename( $e->getFile() ), $e->getLine() ), 'import/logs/download', 500 );
		}

		$filename = sprintf( 'templately-logs-%s-%s.zip', sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), gmdate( 'Ymd-His' ) );

		while ( ob_get_level() > 0 ) {
			@ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $tmp ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a binary download.
		readfile( $tmp );
		@unlink( $tmp );
		exit;
	}

	/**
	 * Everything that goes into the archive, as `name => contents`.
	 *
	 * @return array<string,string>
	 */
	private static function collect_entries() {
		$entries = [];

		foreach ( self::templately_logs() as $file ) {
			$contents = self::read_tail( $file );
			if ( null !== $contents ) {
				$entries[ 'templately/' . basename( $file ) ] = $contents;
			}
		}

		$debug_log = self::wp_debug_log_path();
		if ( $debug_log ) {
			$contents = self::read_tail( $debug_log );
			if ( null !== $contents ) {
				$entries['wp-debug.log'] = $contents;
			}
		}

		$php_log = self::php_error_log_path();
		if ( $php_log && ( ! $debug_log || realpath( $php_log ) !== realpath( $debug_log ) ) ) {
			$contents = self::read_tail( $php_log );
			if ( null !== $contents ) {
				$entries['php-error.log'] = $contents;
			}
		}

		$entries['system-info.txt'] = self::system_info( array_keys( $entries ) );

		return $entries;
	}

	/**
	 * A writable temp path. `wp_tempnam()` lives in wp-admin/includes/file.php,
	 * which REST requests do not load.
	 *
	 * @return string|false
	 */
	private static function temp_file() {
		if ( ! function_exists( 'wp_tempnam' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( function_exists( 'wp_tempnam' ) ) {
			$tmp = wp_tempnam( 'templately-logs.zip' );
			if ( $tmp ) {
				return $tmp;
			}
		}

		$tmp = @tempnam( get_temp_dir(), 'templately-logs' );

		return $tmp ? $tmp : false;
	}

	/**
	 * Write the entries to `$path`: ZipArchive when present, WordPress's
	 * bundled PclZip otherwise.
	 *
	 * @param string               $path
	 * @param array<string,string> $entries
	 * @return bool
	 */
	private static function write_zip( $path, array $entries ) {
		if ( class_exists( ZipArchive::class ) ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				return false;
			}
			foreach ( $entries as $name => $contents ) {
				$zip->addFromString( $name, $contents );
			}
			return $zip->close();
		}

		if ( ! class_exists( 'PclZip' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		}

		@unlink( $path ); // PclZip refuses to create over an existing (empty temp) file.

		$files = [];
		foreach ( $entries as $name => $contents ) {
			$files[] = [
				PCLZIP_ATT_FILE_NAME    => $name,
				PCLZIP_ATT_FILE_CONTENT => $contents,
			];
		}

		$zip = new \PclZip( $path );

		return 0 !== $zip->create( $files );
	}

	/**
	 * @return string[] Absolute paths of the `*.log` files in the Templately log dir.
	 */
	private static function templately_logs() {
		$path = LogFile::path();
		$dir  = $path ? dirname( $path ) : '';

		if ( ! $dir ) {
			$upload_dir = wp_upload_dir( null, false );
			$dir        = empty( $upload_dir['basedir'] ) ? '' : $upload_dir['basedir'] . '/templately/log';
		}

		if ( ! $dir || ! is_dir( $dir ) ) {
			return [];
		}

		$files = glob( trailingslashit( $dir ) . '*.log' );

		return is_array( $files ) ? array_values( array_filter( $files, 'is_readable' ) ) : [];
	}

	/**
	 * @return string|null The file WP_DEBUG_LOG writes to, if it exists.
	 */
	private static function wp_debug_log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			$path = WP_DEBUG_LOG;
		} else {
			$path = WP_CONTENT_DIR . '/debug.log';
		}

		return is_file( $path ) && is_readable( $path ) ? $path : null;
	}

	/**
	 * @return string|null PHP's error_log ini target, if it is a readable file.
	 */
	private static function php_error_log_path() {
		$path = ini_get( 'error_log' );

		if ( ! $path || 'syslog' === $path ) {
			return null;
		}

		return @is_file( $path ) && @is_readable( $path ) ? $path : null;
	}

	/**
	 * A file's contents, or only its last TAIL_BYTES when it is larger.
	 *
	 * @param string $path
	 * @return string|null Null when the file cannot be read.
	 */
	private static function read_tail( $path ) {
		$size = @filesize( $path );
		if ( false === $size ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- partial read of a possibly large file.
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			return null;
		}

		$header = '';
		if ( $size > self::TAIL_BYTES ) {
			fseek( $handle, -self::TAIL_BYTES, SEEK_END );
			$header = sprintf( "[truncated: last %d of %d bytes]\n", self::TAIL_BYTES, $size );
		}

		$contents = stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return false === $contents ? null : $header . $contents;
	}

	/**
	 * Plain-text environment summary. No credentials — the api_key and user
	 * options are never read here.
	 *
	 * @param string[] $included Archive entries added before this file.
	 * @return string
	 */
	private static function system_info( array $included ) {
		global $wp_version;

		if ( ! function_exists( 'get_plugins' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$theme = wp_get_theme();
		$lines = [
			'Generated (UTC):     ' . gmdate( 'Y-m-d H:i:s' ),
			'Site URL:            ' . home_url(),
			'Templately:          ' . ( defined( 'TEMPLATELY_VERSION' ) ? TEMPLATELY_VERSION : 'unknown' ),
			'WordPress:           ' . $wp_version,
			'PHP:                 ' . PHP_VERSION,
			'Server:              ' . ( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown' ),
			'Multisite:           ' . ( is_multisite() ? 'yes' : 'no' ),
			'Theme:               ' . $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'memory_limit:        ' . ini_get( 'memory_limit' ),
			'WP_MEMORY_LIMIT:     ' . ( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '' ),
			'max_execution_time:  ' . ini_get( 'max_execution_time' ),
			'upload_max_filesize: ' . ini_get( 'upload_max_filesize' ),
			'post_max_size:       ' . ini_get( 'post_max_size' ),
			'WP_DEBUG:            ' . ( defined( 'WP_DEBUG' ) && WP_DEBUG ? 'on' : 'off' ),
			'WP_DEBUG_LOG:        ' . ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ? 'on' : 'off' ),
			'TEMPLATELY_DEV_API:  ' . ( defined( 'TEMPLATELY_DEV_API' ) && TEMPLATELY_DEV_API ? 'on' : 'off' ),
			'Disk free (uploads): ' . self::disk_free(),
			'',
			'Active plugins:',
		];

		$all_plugins = function_exists( 'get_plugins' ) ? get_plugins() : [];
		foreach ( (array) get_option( 'active_plugins', [] ) as $plugin ) {
			$lines[] = '  - ' . $plugin . ( isset( $all_plugins[ $plugin ] ) ? ' ' . $all_plugins[ $plugin ]['Version'] : '' );
		}

		$lines[] = '';
		$lines[] = 'Files in this archive:';
		foreach ( $included as $entry ) {
			$lines[] = '  - ' . $entry;
		}
		if ( ! $included ) {
			$lines[] = '  (no log files were found)';
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Free space on the uploads volume. Many shared hosts disable
	 * disk_free_space() — calling a disabled function is a fatal on PHP 8.
	 *
	 * @return string
	 */
	private static function disk_free() {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return 'unavailable';
		}

		$upload_dir = wp_upload_dir( null, false );
		$free       = empty( $upload_dir['basedir'] ) ? false : @disk_free_space( $upload_dir['basedir'] );

		return false === $free ? 'unavailable' : size_format( (float) $free );
	}
}
