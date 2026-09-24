<?php
namespace Templately\Utils;

use Templately\Modules\Auth\REST\Login;
use Templately\Utils\Response\ErrorCode;
use Templately\Utils\Response\ResponseNormalizer;
use Templately\Utils\Response\RetryPolicy;
use WP_Error;

class Http extends Base {
    /**
     * API Endpoint
     * @var string
     */
    private $url = 'https://app.templately.com/api/plugin';

    /**
     * Development Mode
     * @var boolean
     */
    private $dev_mode = false;

    /**
     * API Query
     * @var string
     */
    public $query = null;
    /**
     * API Endpoint
     * @var string
     */
    public $endpoint = null;

    /**
     * Setting the development mode.
     */
    public function __construct() {
        $this->dev_mode = Helper::is_dev_api();
    }

    /**
     * Determining the endpoint URL based on the mode.
     *
     * @return string
     */
    public function url() {
        if ( Helper::is_dev_api() ) {
            $this->url = 'https://app.templately.dev/api/plugin';
        }

        /**
         * Filter the API endpoint URL
         *
         * @since 3.5.0
         * @param string $url The endpoint URL
         */
        $this->url = apply_filters('templately_dev_api_endpoint_url', $this->url);

        return $this->url;
    }

    /**
 * Generate Google OAuth authentication URL
 *
 * @param string $redirect_to Optional redirect path after authentication
 * @return string The Google auth URL with query parameters
 */
public function google_auth_url($redirect_to = '', $current_url = '') {
    $base_url = $this->url();
    // Replace /api/plugin with /api/auth/plugin/google
    $auth_url = str_replace('/api/plugin', '/api/auth/plugin/google', $base_url);

    // Get the referer to return to the exact same page we initiated login from securely
    if ( ! empty( $current_url ) ) {
        $referer = esc_url_raw( $current_url );
    } else {
        $referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
    }

    $return_url = wp_validate_redirect( $referer, '' );

    if ( empty( $return_url ) ) {
        $return_url = admin_url( 'admin.php?page=templately' );
    }

    // Unique random state — doubles as cache busting and as the CSRF token the
    // callback validates. Only minted into a transient for a logged-in user:
    // this endpoint is public, and an anonymous caller could otherwise flood
    // wp_options with tokens that can never authorize anything.
    $state       = wp_generate_password( 32, false );
    $state_owner = get_current_user_id();

    if ( $state_owner > 0 ) {
        Database::set_transient( 'google_state_' . $state, $state_owner, 15 * MINUTE_IN_SECONDS );
    }

    $return_params = [
        'templately_google_login' => '1',
        'templately_state'        => $state,
    ];

    // Add redirect-to parameter if provided
    if (!empty($redirect_to)) {
        $return_params['redirect-to'] = $redirect_to;
    }

    $site_url_with_params = add_query_arg($return_params, $return_url);

    $query_params = [
        'site_url' => urlencode($site_url_with_params),
        'site_ip' => Helper::get_ip(),
        'state' => $state,
    ];

    return add_query_arg($query_params, $auth_url);
}

/**
     * @param  array $args
     * @return string
     */
    protected function prepareArgs( $args ) {
        $prepareArgs = "";
        foreach ( $args as $key => $value ) {
            switch ( true ) {
                case is_int( $value ):
                case is_bool( $value ):
                case is_string( $value ) && ( $value === 'true' || $value === 'false' ):
                    $prepareArgs .= "$key:" . $value . ",";
                    break;
                default:
                    $prepareArgs .= "$key:" . '"' . Helper::esc_json_string( $value ) . '"' . ",";
                    break;
            }
        }

        return rtrim( $prepareArgs, ',' );
    }

    /**
     * Preparing query for the endpoint.
     *
     * @param string $query_name
     * @param string $params
     * @param array $funcArgs
     * @param array ...$args
     * @return Http
     */
    public function query( $query_name, $params, $funcArgs = [], ...$args ) {
        $query = '{';
        $query .= $query_name;
        if ( is_array( $funcArgs ) && ! empty( $funcArgs ) ) {
            $query .= "(" . $this->prepareArgs( $funcArgs ) . ")";
        }
        if ( ! empty( $params ) ) {
            $query .= "{";
            $query .= $params;
            $query .= "}";
        }
        $query .= '}';

        $this->endpoint = $query_name;
        $this->query    = ! empty( $args ) ? sprintf( $query, ...$args ) : $query;
        return $this;
    }

    /**
     * Preparing mutation for the endpoint.
     *
     * @param string $mutate
     * @param string $params
     * @param array $funcArgs
     * @param array ...$args
     * @return Http
     */
    public function mutation( $mutate, $params, $funcArgs = [], ...$args ) {
        $this->query( $mutate, $params, $funcArgs, ...$args );
        $mutation = 'mutation';
        $mutation .= $this->query;
        $this->endpoint = $mutate;
        $this->query    = ! empty( $args ) ? sprintf( $mutation, ...$args ) : $mutation;
        return $this;
    }

    /**
     * This function is responsible for Remote HTTP POST
     *
     * @param string $query
     * @param array $args
     * @return mixed
     */
    public function post( $args = [] ) {
        if ( empty( $query ) ) {
            $query = $this->query;
        }

        $headers = [
            'Content-Type'         => 'application/json',
            'Accept'               => 'application/json',
            'x-templately-ip'      => Helper::get_ip(),
            'x-templately-url'     => home_url( '/' ),
            'x-templately-version' => TEMPLATELY_VERSION,
        ];

        if ( ! empty( $args['headers'] ) ) {
            $headers = wp_parse_args( $args['headers'], $headers );
            unset( $args['headers'] );
        }

        if ( defined( 'TEMPLATELY_DEBUG_LOG' ) && TEMPLATELY_DEBUG_LOG ) {
            Helper::log( 'URL: ' . $this->url() );
            Helper::log( 'QUERY: ' . $query );
        }

        $_default_args = [
            'timeout' => $this->dev_mode ? 120 : 30,
            'headers' => $headers,
            'body'    => wp_json_encode( [
                'query' => $query
            ] )
        ];

        $args = wp_parse_args( $args, $_default_args );

        // 043 / PRD PHP-1 — the retry decision moved to RetryPolicy.
        //
        // This loop retried on WP_Error only, and with NO DELAY: three requests
        // within milliseconds at a server that had just failed to answer one. It
        // also treated every HTTP status as final, so a 502 from a restarting
        // gateway was never retried at all. RetryPolicy adds the transient
        // statuses and a jittered backoff.
        $attempt    = 0;
        $maxRetries = defined( 'TEMPLATELY_HTTP_RETRY' ) ? (int) TEMPLATELY_HTTP_RETRY : RetryPolicy::MAX_ATTEMPTS;

        // Entry-point marker (engagement telemetry). A URL QUERY PARAM so the cloud's
        // access logs capture it with zero cloud-side code — never a GraphQL argument
        // (unknown arguments fail GraphQL validation; a query param on the endpoint
        // URL is ignored by the resolver). Appended here, NOT in url(): url() also
        // feeds google_auth_url(), which must stay clean.
        $request_url = $this->url();
        if ( '' !== Helper::get_request_source() ) {
            $request_url = add_query_arg( 'tl_source', Helper::get_request_source(), $request_url );
        }

        while ( true ) {
            $response = wp_remote_post( $request_url, $args );

            if ( $attempt + 1 >= $maxRetries || ! RetryPolicy::should_retry( $response, $attempt ) ) {
                break;
            }

            RetryPolicy::wait( $attempt );
            $attempt++;
        }

        $retryCount = $attempt + 1;

        if ( defined( 'TEMPLATELY_DEBUG_LOG' ) && TEMPLATELY_DEBUG_LOG ) {
            Helper::log( 'Retry Count: ' . $retryCount );
            // Helper::log( 'RAW RESPONSE: ' );
            // Helper::log( $response );
            // Helper::log( 'END RAW RESPONSE' );
        }

        return $this->maybeErrors( $response, $args );
    }

    /**
     * Formatting the self::post() response
     *
     * @param mixed $response
     * @param array $args
     * @return mixed
     */
    private function maybeErrors( &$response, $args = [] ) {
        // 043 FR-003 — every shape the cloud can return is classified in ONE
        // place now. The hand-rolled cascade this replaced grew a branch per
        // discovered shape and still disagreed with the equivalent cascade in
        // `Helper::make_api_request()`; see the 28 captured fixtures in
        // `specs/043-core-api-response-contract/fixtures/`.
        $normalized = ResponseNormalizer::normalize( $response, [
            'endpoint' => $this->endpoint,
        ] );

        if ( $normalized->is_error() ) {
            $error = $normalized->error();

            if ( defined( 'TEMPLATELY_DEBUG_LOG' ) && TEMPLATELY_DEBUG_LOG ) {
                Helper::log( 'ERROR: ' . $error->code() . ' — ' . $error->message() );
            }

            // An expired session still tears down the stored login — but it now
            // ALSO returns a real error. It used to return a plain array
            // (`['redirect' => true, …]`), which every `is_wp_error()` caller
            // read as SUCCESS and happily passed on as a payload. That is the
            // INV-2 class of bug this contract exists to remove.
            if ( ErrorCode::AUTH_EXPIRED === $error->code() || ErrorCode::INVALID_API_KEY === $error->code() ) {
                Login::get_instance()->delete();
            }

            return $error;
        }

        $_response = $normalized->payload();

        if ( defined( 'TEMPLATELY_DEBUG_LOG' ) && TEMPLATELY_DEBUG_LOG ) {
            Helper::log( 'RESPONSE: ' );
            Helper::log( $_response );
            Helper::log( 'END RESPONSE' );
        }

        return $_response;
    }

}
