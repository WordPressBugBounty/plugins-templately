<?php

namespace Templately\API;

use WP_REST_Request;
use WP_Error;
use Templately\Utils\Helper;
use Templately\Utils\Options;

/**
 * Sites API Class
 *
 * Handles site-related API endpoints including site migration.
 */
class Sites extends API {

    /**
     * Register REST API routes
     */
    public function register_routes() {
        $this->post('sites/migrate', [$this, 'migrate_site']);
    }

    /**
     * Migrate site connection from old URL to new URL
     *
     * Calls the Templately API to transfer site connection from a previously
     * connected site to the current site.
     *
     * @return array|WP_Error Response data or error
     */
    public function migrate_site() {
        $api_key = $this->utils('options')->get('api_key');

        if (empty($api_key)) {
            return $this->error(
                'missing_api_key',
                __('API key is required for site migration.', 'templately'),
                'sites/migrate',
                401
            );
        }

        $new_url = home_url('/');
        $user    = $this->utils('options')->get('user');
        $old_url = isset($user['site_url']) ? base64_decode( $user['site_url'] ) : '';

        // Build the API URL
        $api_url = Helper::get_api_url('v1/migrate/sites');

        // Make the API request
        $response = wp_remote_request($api_url, [
            'method'  => 'POST',
            'timeout' => 30,
            'headers' => [
                'Authorization'        => 'Bearer ' . $api_key,
                'Content-Type'         => 'application/json',
                'x-templately-url'     => $new_url,
                'x-templately-version' => defined('TEMPLATELY_VERSION') ? TEMPLATELY_VERSION : '1.0.0',
            ],
            'body' => json_encode([
                'old_url' => $old_url,
                'new_url' => $new_url,
            ]),
        ]);

        if (is_wp_error($response)) {
            return $this->error(
                'api_request_failed',
                $response->get_error_message(),
                'sites/migrate',
                500
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code !== 200) {
            $error_message = $response_body['message'] ?? __('Site migration failed.', 'templately');
            return $this->error(
                'migration_failed',
                $error_message,
                'sites/migrate',
                $response_code
            );
        }

        // Clear the disconnection status and update site_url on successful migration
        Helper::clear_site_disconnection();

        // Return success response
        return $this->success([
            'status'  => 'success',
            'message' => __('Site successfully migrated. You can now import templates.', 'templately'),
            'data'    => $response_body,
        ]);
    }
}
