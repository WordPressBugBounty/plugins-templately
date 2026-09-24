<?php

namespace Templately\API;

use WP_Error;
use WP_REST_Server;
use WP_REST_Request;
use function ucfirst;

use WP_REST_Response;
use Templately\Utils\Base;
use Templately\Utils\Http;
use Templately\Utils\Plan;
use Templately\Core\Platform_Registry;
use function call_user_func;

use Templately\Utils\Helper;
use Templately\Utils\Options;
use Templately\Modules\Auth\REST\Login;
use Templately\Utils\Response\Envelope;
use function register_rest_route;

/**
 * @method Http http()
 * @method Options options()
 * @method Options|Http|Helper utils( string $name )
 */
abstract class API extends Base {
	protected $api_key;
	protected $request;

	private static $allowed_classes = [
		'utils' => [
			'options',
			'http',
			'helper'
		],
		'api' => [
			'dependencies',
		],
	];

	public function __construct() {
		/**
		 * Registering as a submodule of the API module
		 */
		Platform_Registry::get_instance()->add( (object) [
            'object' => $this
        ], 'API' );
	}

	private static function is_allowed( $type, $class ){
		if( ! array_key_exists( $type, self::$allowed_classes ) ) {
			return false;
		}
		if( ! class_exists( '\\Templately\\'. ucfirst( $type ) .'\\' . ucfirst( $class ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * @param $type
	 * @param $parameters
	 *
	 * @return mixed|void
	 */
	public static function __callStatic( $type, $parameters = [] ){
		return ( new static )->call_user_func( $type, $parameters );
	}

	/**
	 * @param $type
	 * @param $parameters
	 *
	 * @return mixed|void
	 */
	public function __call( $type, $parameters = [] ){
		return $this->call_user_func( $type, $parameters );
	}

	/**
	 * @param $type
	 * @param $parameters
	 *
	 * @return mixed|void
	 */
	protected function call_user_func( $type, $parameters = [] ) {
		if( $type === 'http' || $type === 'options' ) {
			$parameters[0] = $type;
			$type = 'utils';
		}
		if( ! empty ( $parameters[0] ) && self::is_allowed( $type, $parameters[0] ) ) {
			return call_user_func( [ '\\Templately\\'. ucfirst( $type ) .'\\' . ucfirst( $parameters[0] ), 'get_instance' ] );
		}

		Helper::trigger_error( $this );
	}

	protected function get_namespace( $endpoint = '' ) {
		return '/' . TEMPLATELY_API_NAMESPACE . ( ! empty( $endpoint ) ? "/$endpoint" : '' );
	}

	/**
	 * @param string $param
	 * @param mixed $default
	 * @param string $sanitizer
	 *
	 * @return false|mixed
	 */
	public function get_param( $param, $default = '', $sanitizer = 'sanitize_text_field' ) {
		$_value = $this->request->get_param( $param );

		// An ABSENT param — or an explicitly empty string — falls back to the default.
		//
		// This used to guard with `! empty()`, which is ALSO false for `0`, `'0'`,
		// `false` and `[]`. Those are legitimate values a caller may have sent
		// deliberately: a page number of 0, an explicit `false` flag, an empty array
		// meaning "clear the selection". They were silently replaced by the default,
		// and the endpoint could not tell "not sent" from "sent as zero".
		//
		// Empty string stays a fallback on purpose — callers like
		// `get_param( 'search', '' )` rely on it, and "" carries no information a
		// missing param does not.
		if ( null === $_value || '' === $_value ) {
			return $default;
		}

		return self::sanitize_recursive( $_value, $sanitizer );
	}

	/**
	 * Apply a sanitizer through nested arrays.
	 *
	 * `array_map( $sanitizer, $value )` only reached the FIRST level, so a nested
	 * array left its inner values untouched.
	 *
	 * @param mixed           $value
	 * @param callable|string $sanitizer
	 * @return mixed
	 */
	private static function sanitize_recursive( $value, $sanitizer ) {
		if ( ! is_callable( $sanitizer ) ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			return array_map(
				function ( $item ) use ( $sanitizer ) {
					return self::sanitize_recursive( $item, $sanitizer );
				},
				$value
			);
		}

		return call_user_func( $sanitizer, $value );
	}

	/**
	 * @param $request WP_REST_Request for getting all route request in time.
	 *
	 * @return WP_Error|boolean
	 */
	public function _permission_check( WP_REST_Request $request ) {
		$this->request = $request;
		$this->api_key = $this->utils('options')->get( 'api_key' );
		if(!current_user_can('delete_posts')){
			return false;
		}

		add_filter('wp_redirect', '__return_false', 999);

		return $this->permission_check( $request );
	}

	/**
	 * @param $request WP_REST_Request for getting all route request in time.
	 *
	 * @return WP_Error|boolean
	 */
	public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;
		$this->api_key = $this->utils('options')->get( 'api_key' );


		if ( ! empty( $this->api_key ) ) {
			return true;
		}

		$_route = $request->get_route();
		return $this->permission_error( '', $_route );
	}

	/**
	 * @param $message
	 * @param $endpoint
	 *
	 * @return WP_Error
	 */
	protected function permission_error( $message, $endpoint = '') {
        if( empty( $message ) ) {
            $message = __( 'Your session has expired. Please log in again.', 'templately' );
        }

        $_additional_data = [
            'status'   => rest_authorization_required_code(),
        ];

        if( ! empty( $endpoint ) ) {
            $_additional_data['endpoint'] = $endpoint;
        }

        // One definition of "logged out" (043 / PRD PHP-1). This used to remove its
        // own list of FIVE keys while Login removed EIGHT, so a session expiring
        // through this path left `global_login`, `total_download_counts` and
        // `templates_in_clouds` behind — stale data from the previous account, in a
        // state that was neither logged in nor logged out.
		Login::force_logout();

		// The code stays `invalid_api_key`: it is an outward contract several
		// callers still branch on, and RestEnvelope already maps it to
		// AUTH_EXPIRED on the wire. Changing it here would buy nothing and break
		// those callers.
		return new WP_Error( 'invalid_api_key', $message, $_additional_data );
	}

	public function get( $endpoint, $callback, $args = [] ){
		return $this->register_endpoint( $endpoint, $callback, $args, WP_REST_Server::READABLE );
	}
	public function post( $endpoint, $callback, $args = [] ){
		return $this->register_endpoint( $endpoint, $callback, $args );
	}

	public function register_endpoint( $endpoint, $callback, $args = [], $methods = WP_REST_Server::CREATABLE ) {
		return register_rest_route(
			TEMPLATELY_API_NAMESPACE,
			$endpoint,
			[
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => [ $this, '_permission_check' ],
				'args'                => $args,
			]
		);
	}

	public function response( $response, $endpoint, $status = 500, $additional_data = [] ) {
		if ( $response instanceof WP_Error ) {
			return $this->error(
				$response->get_error_code(),
				$response->get_error_message(),
				$endpoint,
				$status,
				$additional_data
			);
		}

		return $this->success( $response );
	}

	/**
	 * @param $data
	 *
	 * @return WP_REST_Response
	 */
	public function success( $data ) {
		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * The canonical success envelope (spec 043 / FR-001).
	 *
	 * Replaces the former `successWithData()`, which hand-rolled the same
	 * `{ success, data }` shape in one place while the rest of the plugin
	 * invented its own. The shape now comes from `Envelope`, which is what the
	 * schema and both language halves are tested against.
	 *
	 * @param mixed $data The payload.
	 * @param array $meta Optional metadata (pagination, timings, …).
	 *
	 * @return WP_REST_Response
	 */
	public function envelope( $data, $meta = [] ) {
		return $this->success( Envelope::success( $data, $meta ) );
	}
	/**
	 * @param $error_code string
	 * @param $error_message string|array
	 * @param $endpoint string
	 * @param $status int
	 * @param $additional_data array
	 *
	 * @return WP_Error
	 */
	public function error( $error_code, $error_message, $endpoint = '', $status = 500, $additional_data = [] ) {
		return Helper::error( $error_code, $error_message, $endpoint, $status, $additional_data );
	}
	/**
	 * @param $plan
	 *
	 * @return int
	 */
	public function get_plan( $plan = 'all' ) {
		return Plan::get( $plan );
	}

	/**
	 * 026/FR-003/D5 — the standard AI-generation response envelope.
	 *
	 * Every generation endpoint emits `{ success, terminal, code, message }`. The
	 * client's retry logic keys off `terminal` ONLY: `terminal:true` ⇒ stop,
	 * `terminal:false` ⇒ keep polling. `code` is for diagnostics/messages.
	 *
	 * The envelope is ADDITIVE — the existing payload ($extra: process_id,
	 * templates, is_local_site, status, …) is merged in, so current consumers
	 * keep reading their fields while gaining the uniform terminal/code signal.
	 *
	 * Code taxonomy:
	 *   ok              terminal, success   — content ready / action succeeded
	 *   pending         retryable, success  — accepted, still working
	 *   not_ready       retryable, success  — content not on disk yet; keep polling
	 *   invalid_process terminal, failure   — unknown/expired process id
	 *   unauthorized    terminal, failure   — api_key/user mismatch
	 *   remote_failed   terminal, failure   — remote AI service terminal failure
	 *   expired         terminal, failure   — process/session past retention
	 *   internal_error  terminal, failure   — unexpected server error
	 *
	 * @param string $code    one of the taxonomy slugs
	 * @param string $message human, i18n
	 * @param array  $extra   existing payload fields to merge (envelope keys win)
	 * @return array
	 */
	public static function ai_envelope( $code, $message = '', $extra = [] ) {
		return array_merge( (array) $extra, [
			'success'  => self::ai_code_is_success( $code ),
			'terminal' => self::ai_code_is_terminal( $code ),
			'code'     => $code,
			'message'  => $message,
		] );
	}

	/**
	 * Whether the client should STOP retrying for this code (FR-003).
	 * `pending` / `not_ready` are the polling states. `internal_error` is also
	 * non-terminal (mirroring AI_INTERNAL_ERROR's retryable=true in the
	 * registry — the fold invariant is terminal === !retryable): transient
	 * manifest/session races produce it, and a single occurrence was killing a
	 * paid, still-running generation. The client poll is attempt-capped, so a
	 * genuinely permanent internal error still terminates, just later.
	 */
	public static function ai_code_is_terminal( $code ) {
		return ! in_array( $code, [ 'pending', 'not_ready', 'internal_error' ], true );
	}

	/** Whether this code represents a non-error response (FR-003). */
	public static function ai_code_is_success( $code ) {
		return in_array( $code, [ 'ok', 'pending', 'not_ready' ], true );
	}
}