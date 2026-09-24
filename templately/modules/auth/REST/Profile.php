<?php

namespace Templately\Modules\Auth\REST;

use Templately\API\API;
use Templately\Utils\Helper;

class Profile extends API {

	public function register_routes() {
		$this->get( 'profile/sync', [ $this, 'sync' ] );
		$this->get( 'profile/verified', [ $this, 'verified' ] );
	}

	public function sync() {
		// `subscription` (with `subscription_plan_id`) must be requested here too,
		// not just on connect: /profile/sync overwrites the stored `user` record
		// wholesale, so omitting the node DROPS the id the Subscription screen's
		// CurrentPlanCard uses to resolve the billing interval — and the card then
		// falls back to guessing "Lifetime" from an empty `plan_expire_at`.
		// Keep the `subscription` field set in step with `Login::login()` — the
		// Subscription screen renders from whichever of the two answered last, so a
		// field missing here silently degrades the card after a profile sync.
		$query = 'status, message, user{ id, name, first_name, last_name, display_name, email, profile_photo, joined, is_verified, is_restricted_company_user, api_key, plan, plan_expire_at, my_cloud{ limit, usages, last_pushed }, favourites{ id, type }, show_notice, reviews{ type, type_id, rating }, subscription { id, name, sites, subscription_plan_id, ends_at, plan_type, cancel_at_period_end } }';

		$funcArgs = [
			'api_key'  => $this->api_key,
			'site_url' => home_url( '/' ),
			'ip'       => Helper::get_ip()
		];

		$response = $this->http()->mutation( 'connectWithApiKey', $query, $funcArgs )->post();

		if ( is_wp_error( $response ) ) {
			return $response;
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

		if ( ! empty( $response['user']['reviews'] ) ) {
			$_reviews = $this->utils( 'helper' )->normalizeReviews( $response['user']['reviews'] );
			$this->utils( 'options' )->set( 'reviews', $_reviews );

			unset( $response['user']['reviews'] );
			$meta['reviews'] = $_reviews;
		}

		if ( ! empty( $response['user'] ) && is_array( $response['user'] ) ) {
			/**
			 * The cloud API key must never be persisted here or sent to the client.
			 * Under a global login this key belongs to the admin, while any user with
			 * `delete_posts` can reach this endpoint. Login and SignUp already drop it.
			 */
			unset( $response['user']['api_key'] );

			$response['user']['site_url'] = base64_encode( home_url( '/' ) );
			$response['user']['ip']       = Helper::get_ip();
		}

		$this->utils( 'options' )->set( 'user', $response['user'] );
		$response['user']['meta'] = Login::get_instance()->user_meta( $meta );

		return $this->success( $response );
	}

	public function verified() {
		$funcArgs = [
			'api_key' => $this->api_key
		];

		$response = $this->http()->query( 'isVerifiedUser', '', $funcArgs )->post();

		if ( $response && !is_wp_error( $response ) ) {
			$user = $this->utils( 'options' )->get( 'user' );

			// Options::get('user') returns the default `false` when no local user is
			// stored. Writing `$user['is_verified']` onto a scalar raises a PHP warning
			// and would persist a corrupted `user` option — guard for the array.
			if ( is_array( $user ) ) {
				$user['is_verified'] = true;
				$this->utils( 'options' )->set( 'user', $user );
			}
		}

		return $response;
	}
}
