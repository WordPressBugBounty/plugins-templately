<?php
namespace Templately\Utils;

use Templately\API\Login;
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
public function google_auth_url($redirect_to = '') {
    $base_url = $this->url();
    // Replace /api/plugin with /api/auth/plugin/google
    $auth_url = str_replace('/api/plugin', '/api/auth/plugin/google', $base_url);

    // Build the site_url with admin-ajax path
    $admin_url = admin_url('admin-ajax.php');
    $admin_params = [
        'action' => 'templately_google_login',
    ];

    // Add redirect-to parameter if provided
    if (!empty($redirect_to)) {
        $admin_params['redirect-to'] = $redirect_to;
    }

    $site_url_with_params = add_query_arg($admin_params, $admin_url);

    $query_params = [
        'site_url' => urlencode($site_url_with_params),
        'site_ip' => Helper::get_ip(),
        'state' => wp_generate_password(32, false) // Add unique random state for cache busting
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
                    $prepareArgs .= "$key:" . '"' . $value . '"' . ",";
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
            'timeout' => $this->dev_mode ? 40 : 30,
            'headers' => $headers,
            'body'    => wp_json_encode( [
                'query' => $query
            ] )
        ];

        $retryCount = 0;
        $maxRetries = defined('TEMPLATELY_HTTP_RETRY') ? TEMPLATELY_HTTP_RETRY : 3;
        $args       = wp_parse_args( $args, $_default_args );
        do {
            $response = wp_remote_post( $this->url(), $args );
            $retryCount++;
        } while ( is_wp_error( $response ) && $retryCount < $maxRetries );

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
        if ( $response instanceof WP_Error ) {
            return $response; // Return WP_Error, if it is an error.
        }

        // Check for verification header before processing response body
        Helper::check_verification_header( $response );
        Helper::check_site_disconnection( $response );

        $response_code    = wp_remote_retrieve_response_code( $response );
        $response_message = wp_remote_retrieve_response_message( $response );

        /**
         * Retrieve Data from Response Body.
         */
        $response = json_decode( wp_remote_retrieve_body( $response ), true );
        /**
         * If the graphql hit with any error.
         */
        if ( ! empty( $response['errors'] ) ) {
            if ( defined( 'TEMPLATELY_DEBUG_LOG' ) && TEMPLATELY_DEBUG_LOG ) {
                Helper::log( 'ERROR: ' );
                Helper::log( $response['errors'] );
                Helper::log( 'END ERROR' );
            }
            if ( is_array( $response['errors'] ) ) {
                $wp_error = new WP_Error;
                array_walk( $response['errors'], function ( $error ) use ( &$wp_error ) {
                    if ( isset( $error['message'] ) ) {
                        if ( $error['message'] === 'validation' ) {
                            array_walk( $error['extensions'], function ( $_error, $_error_key ) use ( &$wp_error ) {
                                if ( $_error_key == 'validation' ) {
                                    array_walk( $_error, function ( $v_error, $key ) use ( &$wp_error ) {
                                        $wp_error->add( "{$key}_error", $v_error[0] );
                                    } );
                                }
                            } );
                        } else {
                            $error_data = [];
                            if(!empty($error["extensions"]["statusText"])) {
                                $error_data["statusText"] = $error["extensions"]["statusText"];
                            }
                            if ( isset( $error['debugMessage'] ) ) {
                                $wp_error->add( 'templately_graphql_error', $error['debugMessage'] );
                            } else {
                                $wp_error->add( 'templately_graphql_error', $error['message'], $error_data );
                            }
                        }
                    }
                } );

                if( $wp_error->get_error_code() === 'templately_graphql_error' ) {
                    if( $wp_error->get_error_message() == 'Unauthorized' ) {
                        $global_user = Login::get_instance()->delete();

                        return [
                            'redirect' => true,
                            'url' => 'sign-in',
                            'user' => $global_user,
                        ];
                    }
                }

                return $wp_error;
            }
        } elseif ( ! empty( $response['status'] ) && $response['status'] === 'error' ) {

            if ( defined( 'TEMPLATELY_DEBUG_LOG' ) && TEMPLATELY_DEBUG_LOG ) {
                Helper::log( 'ERROR: ' );
                Helper::log( $response );
                Helper::log( 'END ERROR' );
            }

            $error_data = [];
            if ( ! empty( $response['statusText'] ) ) {
                $error_data['statusText'] = $response['statusText'];
            }

            $error_message = ! empty( $response['message'] ) ? $response['message'] : __( 'Unknown error occurred', 'templately' );

            return new WP_Error( 'templately_api_error', $error_message, $error_data );
        }

        $_response = isset( $response['data'][$this->endpoint] ) ? $response['data'][$this->endpoint] : [];
        // {"data":{"connectWithApiKey":{"status":"error","message":"Invalid API key.","user":null}}}
        if ( ! empty( $response['status'] ) && $response['status'] === 'error' ) {

        }

        if ( defined( 'TEMPLATELY_DEBUG_LOG' ) && TEMPLATELY_DEBUG_LOG ) {
            Helper::log( 'RESPONSE: ' );
            Helper::log( $_response );
            Helper::log( 'END RESPONSE' );
        }

        return $_response;
    }
}
