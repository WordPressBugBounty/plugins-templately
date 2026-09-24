<?php
namespace Templately\Modules\FullSiteImport\Utils;

use Templately\Utils\Helper;
use WP_REST_Request;

/**
 * Class SignatureVerifier
 *
 * Verifies HMAC-SHA256 signatures from Templately backend callbacks
 * to prevent arbitrary file write vulnerabilities.
 *
 * @package Templately\Modules\FullSiteImport\Utils
 */
class SignatureVerifier {

    /**
     * Verify callback signature from Templately backend.
     *
     * @param array  $payload   Request payload data
     * @param string $signature Signature from X-Templately-Signature header
     * @param int    $timestamp Timestamp from X-Templately-Timestamp header
     * @param string $api_key   User's API key (used as secret)
     * @param int    $tolerance Time tolerance in seconds (default: 300 = 5 minutes)
     * @return bool|WP_Error True if valid, WP_Error otherwise
     */
    public static function verify($payload, $signature, $timestamp, $api_key, $tolerance = 300) {
        if (empty($api_key)) {
            return Helper::error(
                'missing_api_key',
                __('API key not provided for signature verification', 'templately'),
                'verify_signature',
                401
            );
        }

        // Validate inputs
        if (empty($signature) || empty($timestamp) || !is_numeric($timestamp)) {
            return Helper::error(
                'invalid_signature_headers',
                __('Invalid signature or timestamp headers', 'templately'),
                'verify_signature',
                401
            );
        }

        // Check timestamp to prevent replay attacks
        if (!self::is_timestamp_valid((int) $timestamp, $tolerance)) {
            return Helper::error(
                'timestamp_expired',
                __('Callback timestamp expired or invalid', 'templately'),
                'verify_signature',
                401
            );
        }

        // Generate expected signature using API key
        $expected_signature = self::generate_signature($payload, (int) $timestamp, $api_key);

        // Use hash_equals to prevent timing attacks
        if (!hash_equals($expected_signature, $signature)) {
            return Helper::error(
                'invalid_signature',
                __('Invalid signature', 'templately'),
                'verify_signature',
                401
            );
        }

        return true;
    }

    /**
     * Whether callback signature verification REJECTS (enforce) or only LOGS (log-only).
     *
     * Defaults to log-only (034 FR-001): during the rollout grace window a mismatch is
     * logged but the request is allowed, so legitimate cloud traffic can never be rejected
     * before signed callbacks are confirmed. Flip to enforce by defining the
     * TEMPLATELY_CALLBACK_SIGNATURE_ENFORCE constant true, or via the
     * `templately_callback_signature_enforce` filter, once signed traffic is confirmed.
     * The flag is time-boxed — to be removed when enforce becomes the permanent default.
     *
     * @return bool True when a verification failure must reject the request.
     */
    public static function is_enforced() {
        if ( defined( 'TEMPLATELY_CALLBACK_SIGNATURE_ENFORCE' ) ) {
            return (bool) TEMPLATELY_CALLBACK_SIGNATURE_ENFORCE;
        }

        return (bool) apply_filters( 'templately_callback_signature_enforce', false );
    }

    /**
     * Verify a callback request and apply the rollout mode (034 FR-001).
     *
     * Reads the signature + timestamp headers, verifies them against the canonical
     * payload, and decides whether the request may proceed:
     *  - on a valid signature → true;
     *  - on a failure in enforce mode → the WP_Error from verify() (caller returns it → 401);
     *  - on a failure in log-only mode → logs the failure code and returns true (allowed).
     *
     * @param WP_REST_Request $request The callback request.
     * @param string          $api_key The already-validated API key (HMAC secret).
     * @param string          $context Endpoint/log context tag.
     * @return true|\WP_Error
     */
    public static function verify_request( WP_REST_Request $request, $api_key, $context = 'callback' ) {
        $signature = $request->get_header( 'x_templately_signature' );
        if ( empty( $signature ) ) {
            $signature = $request->get_header( 'X-Templately-Signature' );
        }

        $timestamp = $request->get_header( 'x_templately_timestamp' );
        if ( empty( $timestamp ) ) {
            $timestamp = $request->get_header( 'X-Templately-Timestamp' );
        }

        $result = self::verify( $request->get_params(), $signature, $timestamp, $api_key );
        if ( true === $result ) {
            return true;
        }

        // verify() returned a WP_Error.
        if ( self::is_enforced() ) {
            return $result;
        }

        // Log-only grace window: record the failure, allow the request through.
        Helper::log( [
            'context' => $context,
            'code'    => is_wp_error( $result ) ? $result->get_error_code() : 'unknown',
            'message' => is_wp_error( $result ) ? $result->get_error_message() : '',
        ], 'callback_signature_unverified' );

        return true;
    }

    /**
     * Check if timestamp is within acceptable tolerance.
     *
     * @param int $timestamp Unix timestamp to check
     * @param int $tolerance Tolerance in seconds
     * @return bool True if timestamp is valid
     */
    private static function is_timestamp_valid($timestamp, $tolerance) {
        $current_time = time();
        $time_difference = abs($current_time - $timestamp);

        return $time_difference <= $tolerance;
    }

    /**
     * Generate HMAC-SHA256 signature for payload.
     *
     * @param array  $payload   Request payload
     * @param int    $timestamp Unix timestamp
     * @param string $api_key   User's API key (used as secret)
     * @return string HMAC signature
     */
    private static function generate_signature($payload, $timestamp, $api_key) {
        $canonical_string = self::create_canonical_string($payload, $timestamp);
        return hash_hmac('sha256', $canonical_string, $api_key);
    }

    /**
     * Create canonical string from payload and timestamp.
     *
     * Only includes security-critical fields in signature to avoid
     * performance issues with large template content.
     *
     * @param array $payload Request payload
     * @param int   $timestamp Unix timestamp
     * @return string Canonical string
     */
    private static function create_canonical_string($payload, $timestamp) {
        // Extract only security-critical fields for signature
        // Exclude large content fields like 'template' and 'error'
        // Also exclude 'isSkipped' as requested
        $signature_fields = [
            'process_id'  => isset($payload['process_id']) ? $payload['process_id'] : null,
            'content_id'  => isset($payload['content_id']) ? $payload['content_id'] : null,
            'template_id' => isset($payload['template_id']) ? $payload['template_id'] : null,
            'type'        => isset($payload['type']) ? $payload['type'] : null,
        ];

        // Remove null values
        $signature_fields = array_filter($signature_fields, function ($value) {
            return $value !== null;
        });

        // Sort keys for consistency
        ksort($signature_fields);

        // JSON encode with consistent flags
        $payload_json = wp_json_encode($signature_fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Combine timestamp and payload
        return $timestamp . '.' . $payload_json;
    }
}
