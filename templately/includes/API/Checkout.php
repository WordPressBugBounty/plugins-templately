<?php

namespace Templately\API;

/**
 * Plugin-initiated checkout.
 *
 * Asks the backend for a frontend checkout URL carrying a short-lived login
 * token bound to the connected api_key account (no front-end re-login), and
 * hands it back to the React app, which redirects the buyer to it. After
 * payment the frontend returns the buyer to `return_url` with
 * `templately_purchase`.
 */
class Checkout extends API {

	public function register_routes() {
		$this->post( 'checkout', [ $this, 'checkout' ] );
	}

	public function checkout() {
		$purchase_type = $this->get_param( 'purchase_type', 'template' );

		/**
		 * Buying a template is a content decision any contributor may make, but a
		 * subscription is bought *for the account* and changes what everyone on it
		 * pays. The base gate is `delete_posts`, so without this a Contributor
		 * could start a plan purchase against the site owner's account.
		 */
		if ( 'subscription' === $purchase_type && ! current_user_can( 'manage_options' ) ) {
			return $this->error(
				'insufficient_permission',
				__( 'Only administrators can change the subscription.', 'templately' ),
				'checkout',
				rest_authorization_required_code()
			);
		}
		$id            = $this->get_param( 'id', 0, 'intval' );
		$return_url    = $this->get_param( 'return_url', admin_url( 'admin.php?page=templately' ), 'esc_url_raw' );

		if ( empty( $id ) ) {
			return $this->error( 'invalid_checkout_item', __( 'No item selected for purchase.', 'templately' ), 'checkout', 422 );
		}

		$funcArgs = [
			'api_key'       => $this->api_key,
			'purchase_type' => $purchase_type,
			'id'            => $id,
			'return_url'    => $return_url,
		];

		foreach ( [ 'item_type', 'billing_interval', 'coupon' ] as $optional ) {
			$value = $this->get_param( $optional, '' );
			if ( ! empty( $value ) ) {
				$funcArgs[ $optional ] = $value;
			}
		}

		$response = $this->http()->mutation( 'pluginCheckout', 'status, message, data', $funcArgs )->post();

		if ( is_wp_error( $response ) ) {
			return $this->error( 'invalid_checkout_response', $response->get_error_message(), 'checkout' );
		}

		$data = ! empty( $response['data'] ) ? json_decode( $response['data'], true ) : [];

		// Frontend checkout URL created — the app redirects the buyer to it.
		if ( ! empty( $data['url'] ) ) {
			/**
			 * The client assigns this straight to `window.location.href`, so verify
			 * it is an https URL on a Templately host before handing it over. The
			 * response is our own API's, but a redirect target that arrives over the
			 * wire and is followed unchecked is an open redirect waiting to happen.
			 */
			$url  = esc_url_raw( $data['url'] );
			$host = wp_parse_url( $url, PHP_URL_HOST );

			$allowed = $host && ( 'templately.com' === $host || 'templately.dev' === $host || str_ends_with( $host, '.templately.com' ) || str_ends_with( $host, '.templately.dev' ) );

			if ( ! $allowed || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
				return $this->error( 'invalid_checkout_url', __( 'The checkout could not be verified. Please try again.', 'templately' ), 'checkout' );
			}

			return $this->success( [ 'url' => $url ] );
		}

		$message = ! empty( $response['message'] ) ? $response['message'] : __( 'Could not start the checkout. Please try again.', 'templately' );

		return $this->error( 'checkout_failed', $message, 'checkout' );
	}
}
