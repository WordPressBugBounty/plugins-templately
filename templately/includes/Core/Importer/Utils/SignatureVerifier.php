<?php
namespace Templately\Core\Importer\Utils;

use Templately\Utils\Helper;

/**
 * Class SignatureVerifier
 *
 * Verifies HMAC-SHA256 signatures from Templately backend callbacks
 * to prevent arbitrary file write vulnerabilities.
 *
 * @package Templately\Core\Importer\Utils
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
