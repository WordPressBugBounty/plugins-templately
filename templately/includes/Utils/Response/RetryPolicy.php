<?php

namespace Templately\Utils\Response;

/**
 * When an outbound cloud request is worth repeating (spec 043 / PRD PHP-1).
 *
 * `Http::post()` retried on `WP_Error` only, three times, **with no delay at
 * all** — a tight loop. That is the worst possible shape: it misses every
 * retryable HTTP status (a 502 from a restarting upstream was final), and for the
 * transport failures it did catch it fired three requests within milliseconds at
 * a server that had just failed to answer one.
 *
 * Decisions live here rather than in the client so the outbound retry and the
 * `retryable` flag the UI shows a Retry button for cannot disagree.
 */
class RetryPolicy {

	/**
	 * Attempts AFTER the first. Beyond this the user is better served by an error
	 * they can act on than by a request that keeps not finishing.
	 */
	const MAX_ATTEMPTS = 3;

	const BASE_DELAY_MS = 300;
	const MAX_DELAY_MS  = 5000;

	/**
	 * HTTP statuses worth repeating.
	 *
	 * All transient by nature: a gateway restarting, a worker timing out, an edge
	 * dropping the connection. Deliberately NO other 4xx — a 401 or a 422 fails
	 * identically however many times it is sent.
	 */
	private static $retryable_statuses = [ 408, 502, 503, 504, 522, 524 ];

	/**
	 * @param mixed $response A `wp_remote_*` result.
	 * @param int   $attempt  0 for the first failure.
	 * @return bool
	 */
	public static function should_retry( $response, $attempt ) {
		if ( $attempt >= self::MAX_ATTEMPTS - 1 ) {
			return false;
		}

		// Transport failure — DNS, refused, reset. Worth another attempt.
		if ( is_wp_error( $response ) ) {
			return true;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		// A 429 is NOT auto-retried. `Retry-After` says when to come back, and
		// looping is precisely what the server is asking us to stop doing; the
		// normalizer surfaces it with the delay so the caller can honour it once.
		return in_array( $status, self::$retryable_statuses, true );
	}

	/**
	 * Backoff in MICROseconds, ready for `usleep()`.
	 *
	 * Exponential with full jitter. The jitter matters more than the curve: every
	 * site that failed on the same upstream blip would otherwise retry in the same
	 * instant and recreate the load that caused it.
	 *
	 * Filterable so tests do not sleep.
	 *
	 * @param int $attempt
	 * @return int
	 */
	public static function delay( $attempt ) {
		$exponential = self::BASE_DELAY_MS * pow( 2, max( 0, $attempt ) );
		$capped      = (int) min( $exponential, self::MAX_DELAY_MS );
		$jittered    = wp_rand( 0, $capped );

		/**
		 * Backoff before the next outbound retry, in milliseconds.
		 *
		 * Return 0 to disable sleeping (what the test suite does).
		 *
		 * @since 3.7.0
		 * @param int $jittered Milliseconds.
		 * @param int $attempt  0 for the first retry.
		 */
		$delay_ms = (int) apply_filters( 'templately_http_retry_delay', $jittered, $attempt );

		return max( 0, $delay_ms ) * 1000;
	}

	/**
	 * Sleep for the computed backoff. No-op when the filter returns 0.
	 *
	 * @param int $attempt
	 * @return void
	 */
	public static function wait( $attempt ) {
		$microseconds = self::delay( $attempt );

		if ( $microseconds > 0 ) {
			usleep( $microseconds );
		}
	}
}
