<?php
/**
 * Auth module (spec 013-authentication-profile).
 *
 * Login/logout/session, signup, Google OAuth, profile sync + verification recheck.
 * Relocated from the monolith: REST classes from includes/API/{Login,SignUp}.php and
 * the auth-owned half of Profile.php; the Google OAuth callback handler from
 * Plugin::google_login_handler(). Behavior byte-identical (same routes, same query
 * params, same option keys).
 *
 * @package Templately
 */

namespace Templately\Modules\Auth;

use Templately\Core\Module_Base;
use Templately\Utils\Database;
use Templately\Utils\Response\ErrorCode;
use Templately\Modules\Auth\REST\Login;
use Templately\Modules\Auth\REST\Profile;
use Templately\Modules\Auth\REST\SignUp;

class Module extends Module_Base {

	public function get_name(): string {
		return 'auth';
	}

	protected function init_hooks(): void {
		add_action( 'init', [ $this, 'google_login_handler' ] );
	}

	public function register_rest_routes(): void {
		Login::get_instance()->register_routes();
		SignUp::get_instance()->register_routes();
		Profile::get_instance()->register_routes();
	}

	public function google_login_handler() {
		// Stop if not a templately google login request
		if ( empty( $_GET['templately_google_login'] ) ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Checked before the token is consumed: the callback can land while the
		// auth cookie is missing (expired session, cookie not yet set), and WP
		// will bounce the user through wp-login and back to this same URL.
		// Burning the token here would fail that legitimate retry.
		if ( ! is_user_logged_in() ) {
			return;
		}

		$state = '';
		if ( ! empty( $_GET['templately_state'] ) ) {
			$state = sanitize_text_field( wp_unslash( $_GET['templately_state'] ) );
		} elseif ( ! empty( $_GET['state'] ) ) {
			$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		}

		$state_user_id = false;
		if ( ! empty( $state ) ) {
			$state_user_id = Database::get_transient( 'google_state_' . $state );
			Database::delete_transient( 'google_state_' . $state );
		}

		$is_authorized = false !== $state_user_id
			&& intval( $state_user_id ) === get_current_user_id()
			&& current_user_can( 'delete_posts' );

		$redirect_url = remove_query_arg( [ 'templately_google_login', 'templately_state', 'api_key', 'error', 'state', 'redirect-to' ] );

		if ( ! $is_authorized ) {
			$error_code = ErrorCode::AUTH_STATE_INVALID;
		} elseif ( ! empty( $_GET['error'] ) ) {
			// The provider answers with one of its own slugs (oauth_failed,
			// provider_error, invalid_state, …). It is DROPPED rather than mapped:
			// the value is attacker-controlled, none of the slugs mean anything
			// different to the user, and forwarding one would put an unvetted
			// string back into a URL the sign-in screen reads.
			$error_code = ErrorCode::AUTH_PROVIDER_FAILED;
		} elseif ( ! empty( $_GET['api_key'] ) ) {
			$request = new \WP_REST_Request( 'POST', '/templately/v1/login' );
			$request->set_param( 'viaAPI', true );
			$request->set_param( 'api_key', sanitize_text_field( $_GET['api_key'] ) );

			/**
			 * @var Login $login
			 */
			$login = Login::get_instance();
			$login->permission_check( $request );

			// login() pins the write target to the acting user itself — no pin
			// here, or its finally would release ours mid-request.
			$response = $login->login();

			if ( ! is_wp_error( $response ) && ! empty( $response['user'] ) ) {
				$redirect_path = ! empty( $_GET['redirect-to'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect-to'] ) ) : '';
				if ( ! empty( $redirect_path ) ) {
					if ( filter_var( $redirect_path, FILTER_VALIDATE_URL ) ) {
						$redirect_url = $redirect_path;
					} else {
						$is_templately = strpos( $redirect_url, 'page=templately' ) !== false;
						$is_elementor  = strpos( $redirect_url, 'action=elementor' ) !== false;
						// Gutenberg editor usually has action=edit or is a block editor page
						$is_gutenberg  = ( strpos( $redirect_url, 'action=edit' ) !== false || strpos( $redirect_url, 'post_type=' ) !== false ) && ! $is_elementor;

						if ( $is_templately || $is_elementor || $is_gutenberg ) {
							$redirect_url = add_query_arg( 'path', ltrim( $redirect_path, '/' ), $redirect_url );

							// Always open the modal in editors after google login
							if ( $is_elementor || $is_gutenberg ) {
								$redirect_url = add_query_arg( 'templately_open_modal', '1', $redirect_url );
							}
						}
					}
				}

				wp_safe_redirect( $redirect_url );
				exit;
			} else {
				$error_code = Login::resolve_error_code( $response );
			}
		} else {
			$error_code = ErrorCode::AUTH_MISSING_API_KEY;
		}

		// ONLY a code travels back — never a message. The message is authored
		// upstream, can contain markup, and anything placed in a query param is
		// attacker-controlled by the time the sign-in screen renders it. The
		// client maps the code to its own translated copy (errorCatalog) and
		// falls back to generic copy for a code it does not recognise.
		$redirect_url = add_query_arg( [
			'templately_error' => rawurlencode( $error_code ),
		], $redirect_url );

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
