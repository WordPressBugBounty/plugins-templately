<?php
/**
 * Resolves a `required_pro_templately` import failure into a purchase
 * reference (FR-010), reusing the existing `Templately\API\Checkout` logic
 * — the same checkout flow the browser dashboard already uses.
 *
 * @package Templately\Modules\McpCore\Support
 */

namespace Templately\Modules\McpCore\Support;

use WP_Error;

class ProGateResolver {

	/**
	 * If $error is the Pro-entitlement gate, re-wrap it with a live checkout
	 * URL attached. Any other error is passed through unchanged.
	 *
	 * @param WP_Error $error
	 * @param int      $template_id
	 * @return WP_Error
	 */
	public static function to_purchase_error( WP_Error $error, int $template_id ): WP_Error {
		if ( $error->get_error_code() !== 'required_pro_templately' ) {
			return $error;
		}

		return new WP_Error(
			'required_pro_templately',
			$error->get_error_message(),
			[ 'purchase' => [ 'checkout_url' => self::resolve_checkout_url( $template_id ) ] ]
		);
	}

	/**
	 * @param int $template_id
	 * @return string|null
	 */
	private static function resolve_checkout_url( int $template_id ) {
		$response = RestDispatcher::dispatch( 'POST', '/templately/v1/checkout', [
			'purchase_type' => 'template',
			'id'            => $template_id,
		] );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data = $response->get_data();

		return $data['url'] ?? null;
	}
}
