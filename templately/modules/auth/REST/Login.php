<?php

namespace Templately\Modules\Auth\REST;

use WP_REST_Request;
use Templately\API\API;
use Templately\Utils\Helper;
use Templately\Utils\Options;
use Templately\Utils\Response\ErrorCode;
use Templately\Utils\Response\ResponseNormalizer;

class Login extends API {
	/**
	 * The plan-catalog cache key. Versioned with the FIELD SET of the query in
	 * `pricing()`, not with a release: a wider query under the old key would keep
	 * serving the narrower cached answer for up to a week.
	 */
	const PRICING_TRANSIENT = 'templately_subscriptions_v3';

    public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;
        $_route = $request->get_route();
        if ( '/templately/v1/login' === $_route ) {
            return true;
        }

        if ( '/templately/v1/pricing' === $_route ) {
            return true;
        }

        if ( '/templately/v1/google-auth-url' === $_route ) {
            return true;
        }

        return parent::permission_check( $request );
    }

    public function register_routes() {
        $this->post( 'login', [$this, 'login'] );
        $this->post( 'logout', [$this, 'logout'] );
        $this->get( 'is-signed', [$this, 'is_signed'] );
        $this->get( 'pricing', [$this, 'pricing'] );
        $this->get( 'google-auth-url', [$this, 'google_auth_url'] );
    }

    public function google_auth_url() {
        // Get redirect_to parameter from request if provided
        $redirect_to = $this->get_param( 'redirect-to', '' );

        // Use client-provided current_url instead of HTTP_REFERER for reliability
        $current_url = $this->get_param( 'current_url', '' );

        $url = $this->http()->google_auth_url( $redirect_to, $current_url );
        return [
            'status' => 'success',
            'url' => $url
        ];
    }

	public function pricing(){
		// Transient key is versioned ON PURPOSE. The field set below widened, and
		// the cache lives for a WEEK — without a new key every site that had
		// visited the Subscription screen recently would keep serving the old,
		// narrow payload and the plan grid would stay full of dashes. Bump the
		// suffix whenever the query changes. (_v3: `is_bundle` + `plugins`, so
		// the Subscription screen can tell a bundle plan from a regular one.)
		$data = get_transient( self::PRICING_TRANSIENT );

		if( is_array( $data ) && ! empty( $data ) ) {
			return $data;
		}

		// Field set drives the Subscription screen's plan comparison grid, so it
		// carries the per-plan limits too, not just price/sites. `is_bundle` and
		// `plugins{ plugin_original_slug }` identify the bundle plans (a site tier
		// sold together with Essential Addons Pro and/or Essential Blocks Pro);
		// without them every bundle renders as one more column under its raw
		// admin name — "Gutenberg PRO bundle", nine of them on the live catalog.
		$query = 'id, price, name, slug, discounted_price, type, sites, coupon, my_cloud_items, pro_items, workspace, fsi_limit, ai_credit, description, is_bundle, plugins{ id, name, plugin_original_slug }';
		$response = $this->http()->query(
			'subscriptionPlans',
			$query
		)->post();

		set_transient( self::PRICING_TRANSIENT, $response, WEEK_IN_SECONDS );

		return $response;
	}

    public function login() {
        $errors    = [];
        $_ip       = Helper::get_ip();
        $_site_url = home_url( '/' );

        $global_signin = (bool) $this->get_param( 'global_signin', false );
        $viaAPI        = (bool) $this->get_param( 'viaAPI', false );
        $email         = $this->get_param( 'email', '', 'sanitize_email' );
        $password      = $this->get_param( 'password' );

        $funcArgs = [
            'ip'       => $_ip,
            'site_url' => $_site_url
        ];

        $postArgs = [];

        if ( $viaAPI ) {
            $api_key             = $this->get_param( 'api_key' );
            $funcArgs['api_key'] = $api_key;

            if ( empty( $api_key ) ) {
                $errors['api_key'] = __( 'API Key field cannot be empty.', 'templately' );
            }
        } else {
            $funcArgs['email']    = $email;
            $funcArgs['password'] = addcslashes( $password, '"' );

            if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
                $errors['email'] = __( 'Make sure you have given a valid email address.', 'templately' );
            }

            if ( empty( $password ) ) {
                $errors['password'] = __( 'Password field cannot be empty.', 'templately' );
            }
        }

        if ( ! empty( $errors ) ) {
            return $this->error( 'login_error', $errors, 'login', 400 );
        }

        // `statusText` is the upstream's machine-readable failure identifier — the
        // same vocabulary ResponseNormalizer maps everywhere else. It is requested
        // here because a refused connect still answers HTTP 200 with a `user` node
        // that merely lacks `api_key`, so the normalizer never sees a failure and
        // the only signal left would be the prose in `message`.
        // `subscription_plan_id` is what CurrentPlanCard matches against the
        // /pricing catalog to resolve the billing interval. Without it the card
        // falls back to guessing from `plan_expire_at` and shows "Lifetime" for
        // every yearly/monthly subscriber whose expiry is momentarily empty.
        // `ends_at`, `plan_type` and `cancel_at_period_end` drive the Subscription
        // screen's renewal line. They are asked for instead of leaning on
        // `plan_expire_at`, which the API resolves with `empty($subscription->ends_at)
        // ?? …` — a boolean that never falls through, so it never carries a date.
        $query = 'status, message, statusText, user{ id, name, first_name, last_name, display_name, email, profile_photo, joined, is_verified, is_company_user, is_restricted_company_user, api_key, plan, plan_expire_at, my_cloud{ limit, usages, last_pushed }, favourites{ id, type }, show_notice, reviews{ type, type_id, rating }, subscription { id, name, sites, subscription_plan_id, ends_at, plan_type, cancel_at_period_end } }';

        $response = $this->http()->mutation(
            $viaAPI ? 'connectWithApiKey' : 'connect',
            $query,
            $funcArgs
        )->post($postArgs);

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['user']['api_key'] ) ) {
            return $this->login_refused( $response );
        }

        $options = $this->utils( 'options' );
        $options->use_current_user( true );

        try {
            return $this->store_connection( $response, $global_signin, $_ip, $_site_url );
        } finally {
            $options->use_current_user( false );
        }
    }

    /**
     * The error for a connect the cloud refused.
     *
     * A refusal arrives as HTTP 200 with a `user` node carrying no `api_key`, so
     * `ResponseNormalizer` never classified it and the only thing left to show
     * was the upstream's prose. That prose is authored remotely, carries markup
     * (the site-limit copy links to /subscription), and — once it travelled back
     * through a redirect query param — was attacker-controlled by the time the
     * sign-in screen rendered it.
     *
     * So the code travels, never the message: `statusText` resolves through the
     * ONE registry map and the client renders its own copy from `errorCatalog`.
     * The message is still attached for diagnostics and as the fallback for a
     * vocabulary word this version does not know — it is rendered as text.
     *
     * @param array $response Decoded cloud response.
     *
     * @return \WP_Error
     */
    private function login_refused( $response ) {
        $status_text = isset( $response['statusText'] ) && is_string( $response['statusText'] )
            ? sanitize_text_field( $response['statusText'] )
            : '';

        $code    = ResponseNormalizer::code_for_status_text( $status_text ) ?: ErrorCode::INVALID_API_KEY;
        $message = ! empty( $response['message'] ) && is_string( $response['message'] )
            ? $response['message']
            : __( 'Invalid API key.', 'templately' );

        return $this->error( $code, $message, 'login', ErrorCode::status( $code ), [
            'context' => [ 'status_text' => $status_text ],
        ] );
    }

    /**
     * Resolve a failed login to the identifier the sign-in screen keys off.
     *
     * Used by the Google callback, which can only hand a single value back
     * through the redirect URL. Anything without a registry code collapses to
     * `INVALID_API_KEY`, so nothing upstream-authored ever reaches the URL.
     *
     * @param mixed $response Result of the login request.
     *
     * @return string
     */
    public static function resolve_error_code( $response ) {
        if ( is_wp_error( $response ) ) {
            $code = $response->get_error_code();

            if ( is_string( $code ) && ErrorCode::exists( $code ) ) {
                return $code;
            }
        }

        return ErrorCode::INVALID_API_KEY;
    }

    /**
     * Persist an authenticated connection against the acting user.
     *
     * @param array  $response      Cloud response, already validated.
     * @param bool   $global_signin Whether the user asked to sign in globally.
     * @param string $_ip           Request IP, echoed back into the profile.
     * @param string $_site_url     Site URL, echoed back into the profile.
     *
     * @return array
     */
    private function store_connection( $response, $global_signin, $_ip, $_site_url ) {

        if ( $global_signin && ! Login::is_globally_signed() ) {
            Options::set_global_login();
        }

        if ( ! empty( $response['user']['api_key'] ) ) {
            $this->utils( 'options' )->set( 'api_key', $response['user']['api_key'] );
            unset( $response['user']['api_key'] );
        }

        $meta = [
            'is_globally_signed' => Login::is_globally_signed(),
            'signed_as_global'   => Login::signed_as_global()
        ];

        if ( ! empty( $response['user']['my_cloud']['last_pushed'] ) ) {
            // Cloud response body = untrusted input: never hydrate objects from it.
            $_cloud_activity = unserialize( $response['user']['my_cloud']['last_pushed'], [ 'allowed_classes' => false ] );
            $this->utils( 'options' )->set( 'cloud_activity', $_cloud_activity );
            $meta['cloud_activity'] = $_cloud_activity;
            unset( $response['user']['my_cloud']['last_pushed'] );
        }

        if ( ! empty( $response['user']['favourites'] ) ) {
            $_favourites = $this->utils( 'helper' )->normalizeFavourites( $response['user']['favourites'] );
            $this->utils( 'options' )->set( 'favourites', $_favourites );

            unset( $response['user']['favourites'] );
            $meta['favourites'] = $_favourites;
        }

		if ( ! empty( $response['user']['reviews'] ) ) {
			$_reviews = $this->utils( 'helper' )->normalizeReviews( $response['user']['reviews'] );
			$this->utils( 'options' )->set( 'reviews', $_reviews );

			unset( $response['user']['reviews'] );
			$meta['reviews'] = $_reviews;
		}

        if(Helper::is_dev_api()){
            $response['user']['is_dev_api'] = true;
        }

        if(! empty( $response['user'] ) && is_array($response['user'])){
            $response['user']['ip']       = $_ip;
            $response['user']['site_url'] = base64_encode( $_site_url );
        }

        $this->utils( 'options' )->set( 'user', $response['user'] );
        $response['user']['meta'] = $this->user_meta( $meta );

        return $response;
    }

    public function logout() {
        // Read the key off the acting user's OWN record. `Options::get()` falls back
        // to the global-login administrator when no target is given, so
        // `$this->api_key` resolves to the administrator's key for any linked user —
        // and the outbound `disconnect` would then revoke the administrator's site on
        // the cloud. Refuse before contacting the cloud when the caller holds no key.
        //
        // No pin here: `force_logout()` (via `delete()` below) holds one, and the pin
        // is a single flag on the singleton — nesting it would release the outer one.
        $api_key = $this->utils( 'options' )->get( 'api_key', '', get_current_user_id() );

        if ( empty( $api_key ) ) {
            return $this->error(
                'logout_error',
                __( 'You are not connected to Templately.', 'templately' ),
                'logout',
                403
            );
        }

        $remote_response = $this->http()->mutation(
            'disconnect',
            'status, message, data',
            [
                'api_key'  => $api_key,
                "site_url" => home_url( '/' )
            ]
        )->post();

        // Fail-safe logout (FR-004): a failed remote call must never leave the
        // user stuck signed in locally. Capture the remote failure (if any) but
        // always proceed to clear local credentials below.
        $remote_error = null;

        if ( is_wp_error( $remote_response ) ) {
            $remote_error = $remote_response->get_error_message();
        } elseif ( ! isset( $remote_response['status'] ) || $remote_response['status'] !== 'success' ) {
            $remote_error = $remote_response['message'] ?? __( 'Failed to invalidate the remote session.', 'templately' );
        }

        // Remove All Metas — unconditional, regardless of remote outcome.
        $global_user = $this->delete();

        $response = [
            'status'  => 'success',
            'message' => __( 'Logged out.', 'templately' )
        ];

        if ( ! empty( $global_user ) ) {
            $response['global_user'] = $global_user;
        }

        if ( $remote_error !== null ) {
            // Surfaced, not swallowed: local state is clean, but the caller should
            // still learn the remote session may not have been invalidated.
            $response['remote_error'] = $remote_error;
        }

        return $response;
    }

	/**
	 * Tear down the stored session — the ONE place that decides what "logged out"
	 * means (spec 043 / PRD PHP-1).
	 *
	 * `API::permission_error()` used to remove its own list of FIVE keys while this
	 * class removed EIGHT, so an expired session cleared through that path left
	 * `global_login`, `total_download_counts` and `templates_in_clouds` behind —
	 * a partially logged-out state carrying stale data from the previous account.
	 * Both paths now call this.
	 *
	 * The removals are PINNED to the acting user. Without the pin,
	 * `Options::user_id()` resolves a `link` user who holds no connection of their
	 * own to the global-login administrator, so a Contributor POSTing `logout`
	 * cleared the ADMINISTRATOR's `_templately_api_key` and `_templately_user`.
	 * `permission_error()` reaches here too — from `Http` on an `Unauthorized`
	 * reply, where no permission gate stands in front of it.
	 *
	 * Trade-off: a linked user hitting `Unauthorized` no longer clears the
	 * administrator's stale key; only the administrator's own request does.
	 *
	 * @return void
	 */
	public static function force_logout() {
		$options = \Templately\Utils\Options::get_instance();

		$options->use_current_user( true );

		try {
			$options
				->remove( 'user' )
				->remove( 'favourites' )
				->remove( 'reviews' )
				->remove( 'cloud_activity' )
				->remove( 'api_key' )
				->remove( 'global_login' )
				->remove( 'total_download_counts' )
				->remove( 'templates_in_clouds' );

			if ( $options->who_am_i() === 'global' ) {
				$options->remove_global_login();
			}
		} finally {
			$options->use_current_user( false );
		}
	}

	public function delete(){
		self::force_logout();

		$global_user_id = $this->utils( 'options' )->is_global();
		$global_user = null;

        if ( $global_user_id !== $this->utils( 'options' )->current_user_id() ) {
            $global_user = $this->utils( 'options' )->get( 'user', false, $global_user_id );

            if ( ! empty( $global_user ) ) {
                if ( is_array( $global_user ) ) {
                    unset( $global_user['api_key'] );
                }

                $global_user['meta'] = $this->user_meta();
            }
        }

		return $global_user;
	}

    public static function is_signed(): array {
        $_response = [
            'status' => 'success'
        ];

        $_user = ( new static )->utils( 'options' )->get( 'user', null );

        if ( ! is_null( $_user ) ) {
            // Profiles stored before 3.7.1 may still carry the cloud API key.
            if ( is_array( $_user ) ) {
                unset( $_user['api_key'] );
            }

            $_user['meta'] = self::get_instance()->user_meta();
        }

        if ( empty( $_user ) ) {
            $_response['status'] = 'error';
        }

        $_response['user'] = $_user;

        return $_response;
    }

    public function user_meta( $meta = [] ): array {
        $_meta = [
            'link_account'       => self::utils( 'options' )->link_account(),
            'unlink_account'     => self::utils( 'options' )->unlink_account(),
            'is_globally_signed' => Login::is_globally_signed(),
            'signed_as_global'   => Login::signed_as_global(),
            'starred'            => self::utils( 'options' )->get( 'favourites' ),
            'reviews'            => self::utils( 'options' )->get( 'reviews' ),
            'cloud_activity'     => self::utils( 'options' )->get( 'cloud_activity' ),
            'has_api'            => rest_sanitize_boolean( self::utils( 'options' )->get( 'api_key' ) )
        ];

        return array_merge( $_meta, $meta );
    }

    public static function is_globally_signed(): bool {
        return rest_sanitize_boolean(  ( new static )->utils( 'options' )->is_globally_signed() );
    }

    public static function signed_as_global(): bool {
        return rest_sanitize_boolean(  ( new static )->utils( 'options' )->signed_as_global() );
    }
}
