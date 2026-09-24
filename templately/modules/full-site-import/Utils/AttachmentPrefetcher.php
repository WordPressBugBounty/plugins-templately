<?php
/**
 * Fetch upcoming attachments a few at a time, in parallel.
 *
 * WPImport used to download every image with its own blocking request on its own fresh
 * connection. On a high-latency link that is almost pure waiting: a 37 KB image cost ~4s
 * (connect, TLS, first byte and body at roughly one round trip each), and an import with 65
 * remote images spent 207 of its 230 seconds that way. Six of the same image requested at
 * once finished in the same 4s as one. Bandwidth was never the limit; round trips were.
 *
 * HOW IT IS USED
 *
 *   1. A caller that knows what it is about to import `enqueue()`s those URLs, in order.
 *   2. `WPImport::fetch_remote_file()` asks `take( $url )` before doing its own request.
 *      A hit hands back a finished temp file. A miss on a QUEUED url fetches a window —
 *      that url plus the next few queued ones — in parallel, then answers.
 *      A url nobody queued is never fetched here: `take()` returns null and the importer's
 *      existing serial request runs exactly as it always has.
 *
 * Windowed and lazy, rather than "download everything first", for two reasons. The import
 * request works to a ~25s budget and streams progress; an up-front pass over every image
 * would be a long silent stall with nothing imported at the end of it. And files land in
 * the SESSION's temp directory, so a window fetched just before a chunk boundary is still
 * there for the next request — and is removed with the session.
 *
 * THREE RULES THIS CLASS MUST NOT BREAK
 *
 * 1. **It may only ever make an import faster, never different.** Every failure — an
 *    unusable URL, a non-200, a redirect, a short file, no parallel transport, a proxy —
 *    ends in `null`, and the importer's own request then decides the outcome. Nothing here
 *    is allowed to turn a download that would have worked into one that does not.
 * 2. **The same URL checks as `wp_safe_remote_get()`.** A pack can point an image anywhere,
 *    so this path must not become the way around `wp_http_validate_url()` or
 *    WP_HTTP_BLOCK_EXTERNAL. Redirects are NOT followed: core validates each hop, a raw
 *    transport call does not, so a redirecting URL is left to the serial path that does.
 * 3. **Never throw, never echo.** It runs inside a live SSE stream.
 *
 * @package Templately\Modules\FullSiteImport
 */

namespace Templately\Modules\FullSiteImport\Utils;

use Templately\Core\Capabilities;

class AttachmentPrefetcher {

	/** How many requests run at once. Filterable: `templately_fsi_prefetch_concurrency`. */
	const DEFAULT_CONCURRENCY = 6;

	/** A parallel request is a shortcut, not the last word — keep it short; the serial path retries. */
	const DEFAULT_TIMEOUT = 30;

	/** @var string[] Queued URLs, in import order. */
	private static $queue = [];

	/** @var array<string,true> URLs already attempted in this request — never retried here. */
	private static $attempted = [];

	/** @var string|null */
	private static $dir = null;

	/** @var callable|null Receives one line per window, for the import's own event log. */
	private static $log = null;

	/** @var array{hits:int,windows:int,fetched:int,failed:int} */
	private static $stats = [ 'hits' => 0, 'windows' => 0, 'fetched' => 0, 'failed' => 0 ];

	/**
	 * Point the cache at the session's temp directory. Without one, nothing is prefetched.
	 *
	 * @param string        $session_dir Absolute path of the session's working directory.
	 * @param callable|null $log         Optional sink for one line per window. This class never
	 *                                   writes to the output stream itself.
	 * @return void
	 */
	public static function boot( $session_dir, $log = null ) {
		self::$log       = is_callable( $log ) ? $log : null;
		self::$queue     = [];
		self::$attempted = [];
		self::$stats     = [ 'hits' => 0, 'windows' => 0, 'fetched' => 0, 'failed' => 0 ];
		self::$dir       = null;

		if ( ! is_string( $session_dir ) || '' === $session_dir || ! is_dir( $session_dir ) ) {
			return;
		}

		$dir = trailingslashit( $session_dir ) . 'prefetch';

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return;
		}

		self::$dir = trailingslashit( $dir );
	}

	/**
	 * Whether parallel fetching can run on this host, right now.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( null === self::$dir ) {
			return false;
		}

		if ( ! Capabilities::get_instance()->has( 'http-parallel-requests' ) ) {
			return false;
		}

		// A configured proxy is honoured by WP_Http and unknown to a raw transport call.
		// Going around it could fail on a locked-down network or, worse, succeed where the
		// site owner meant requests to be routed. Leave those sites on the serial path.
		if ( defined( 'WP_PROXY_HOST' ) && WP_PROXY_HOST ) {
			return false;
		}

		return (bool) apply_filters( 'templately_fsi_prefetch_enabled', true );
	}

	/**
	 * Register URLs that are about to be imported, in the order they will be asked for.
	 *
	 * @param string[] $urls
	 * @return void
	 */
	public static function enqueue( array $urls ) {
		if ( ! self::is_available() ) {
			return;
		}

		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			if ( isset( self::$attempted[ $url ] ) || in_array( $url, self::$queue, true ) ) {
				continue;
			}
			self::$queue[] = $url;
		}
	}

	/**
	 * A finished download for this URL, or null.
	 *
	 * The file is handed over: the caller moves it, and the cache entry is gone.
	 *
	 * @param string $url
	 * @return array{file:string,code:int,headers:array<string,string>}|null
	 */
	public static function take( $url ) {
		if ( ! is_string( $url ) || '' === $url || ! self::is_available() ) {
			return null;
		}

		$cached = self::read( $url );

		if ( null === $cached && in_array( $url, self::$queue, true ) ) {
			self::fetch_window( $url );
			$cached = self::read( $url );
		}

		// Asked for, so no longer upcoming — whatever the outcome.
		self::$queue = array_values( array_diff( self::$queue, [ $url ] ) );

		if ( null === $cached ) {
			return null;
		}

		self::$stats['hits']++;
		@unlink( self::meta_path( $url ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $cached;
	}

	/**
	 * Counters for the log — how much of the import this actually carried.
	 *
	 * @return array{hits:int,windows:int,fetched:int,failed:int}
	 */
	public static function stats() {
		return self::$stats;
	}

	/**
	 * Remove whatever was fetched and never asked for. Called once the import is over.
	 *
	 * @return void
	 */
	public static function purge() {
		if ( null === self::$dir || ! is_dir( self::$dir ) ) {
			return;
		}

		foreach ( (array) glob( self::$dir . '*' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		@rmdir( self::$dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		self::$queue = [];
	}

	/**
	 * Fetch `$url` and the next queued URLs together.
	 *
	 * @param string $url
	 * @return void
	 */
	private static function fetch_window( $url ) {
		$size = (int) apply_filters( 'templately_fsi_prefetch_concurrency', self::DEFAULT_CONCURRENCY );
		$size = max( 1, min( 12, $size ) );

		$start  = array_search( $url, self::$queue, true );
		$window = array_slice( self::$queue, false === $start ? 0 : $start, $size );

		$requests = [];
		foreach ( $window as $candidate ) {
			self::$attempted[ $candidate ] = true;

			if ( null !== self::read( $candidate ) || ! self::is_fetchable( $candidate ) ) {
				continue;
			}

			$requests[ $candidate ] = [
				'url'     => $candidate,
				'type'    => 'GET',
				'headers' => [ 'Accept-Encoding' => 'identity' ],
				'options' => [ 'filename' => self::body_path( $candidate ) ],
			];
		}

		// Whatever was in the window has had its one chance here.
		self::$queue = array_values( array_diff( self::$queue, $window, [ $url ] ) );

		if ( empty( $requests ) ) {
			return;
		}

		self::$stats['windows']++;
		$started = microtime( true );

		$options = [
			'timeout'          => (int) apply_filters( 'templately_fsi_prefetch_timeout', self::DEFAULT_TIMEOUT ),
			'connect_timeout'  => 10,
			'follow_redirects' => false,
			'useragent'        => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
			'verify'           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			'verifyname'       => true,
		];

		/** This filter is documented in wp-includes/class-wp-http.php */
		if ( ! apply_filters( 'https_ssl_verify', true, '' ) ) {
			$options['verify']     = false;
			$options['verifyname'] = false;
		}

		try {
			$responses = \WpOrg\Requests\Requests::request_multiple( $requests, $options );
		} catch ( \Throwable $e ) {
			$responses = [];
		}

		$kept = 0;
		foreach ( $requests as $candidate => $request ) {
			$response = isset( $responses[ $candidate ] ) ? $responses[ $candidate ] : null;

			if ( self::keep( $candidate, $response ) ) {
				self::$stats['fetched']++;
				$kept++;
				continue;
			}

			self::$stats['failed']++;
			self::discard( $candidate );
		}

		if ( null !== self::$log ) {
			try {
				call_user_func(
					self::$log,
					sprintf( 'Fetched %d of %d attachments in parallel in %.1fs', $kept, count( $requests ), microtime( true ) - $started )
				);
			} catch ( \Throwable $e ) {
				// A logger that fails is not a reason to fail a download.
				unset( $e );
			}
		}
	}

	/**
	 * Decide whether a parallel response is good enough to stand in for the serial one.
	 *
	 * Deliberately strict. A rejected file costs one ordinary request; an accepted bad one
	 * becomes a broken image on the customer's site.
	 *
	 * @param string $url
	 * @param mixed  $response
	 * @return bool
	 */
	private static function keep( $url, $response ) {
		if ( ! is_object( $response ) || ! isset( $response->status_code ) || 200 !== (int) $response->status_code ) {
			return false;
		}

		$body = self::body_path( $url );
		$size = file_exists( $body ) ? (int) filesize( $body ) : 0;

		if ( $size <= 0 ) {
			return false;
		}

		$headers = [];
		foreach ( [ 'content-type', 'content-length', 'content-encoding', 'content-disposition' ] as $name ) {
			$value = isset( $response->headers[ $name ] ) ? $response->headers[ $name ] : null;
			if ( is_string( $value ) && '' !== $value ) {
				$headers[ $name ] = $value;
			}
		}

		if ( ! isset( $headers['content-encoding'] ) && isset( $headers['content-length'] ) && (int) $headers['content-length'] !== $size ) {
			return false;
		}

		$meta = wp_json_encode( [ 'url' => $url, 'code' => 200, 'headers' => $headers ] );

		return false !== $meta && false !== file_put_contents( self::meta_path( $url ), $meta ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * The checks `wp_safe_remote_get()` would have made before sending anything.
	 *
	 * @param string $url
	 * @return bool
	 */
	private static function is_fetchable( $url ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'https' !== $scheme && 'http' !== $scheme ) {
			return false;
		}

		$http = _wp_http_get_object();

		return ! $http->block_request( $url );
	}

	/**
	 * @param string $url
	 * @return array{file:string,code:int,headers:array<string,string>}|null
	 */
	private static function read( $url ) {
		if ( null === self::$dir ) {
			return null;
		}

		$meta_path = self::meta_path( $url );
		$body_path = self::body_path( $url );

		if ( ! file_exists( $meta_path ) || ! file_exists( $body_path ) ) {
			return null;
		}

		$meta = json_decode( (string) file_get_contents( $meta_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// The key is a hash, so confirm the entry really is for this URL.
		if ( ! is_array( $meta ) || ! isset( $meta['url'] ) || $meta['url'] !== $url ) {
			return null;
		}

		return [
			'file'    => $body_path,
			'code'    => isset( $meta['code'] ) ? (int) $meta['code'] : 0,
			'headers' => isset( $meta['headers'] ) && is_array( $meta['headers'] ) ? $meta['headers'] : [],
		];
	}

	private static function discard( $url ) {
		@unlink( self::body_path( $url ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( self::meta_path( $url ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	private static function body_path( $url ) {
		return self::$dir . sha1( $url ) . '.bin';
	}

	private static function meta_path( $url ) {
		return self::$dir . sha1( $url ) . '.json';
	}
}
