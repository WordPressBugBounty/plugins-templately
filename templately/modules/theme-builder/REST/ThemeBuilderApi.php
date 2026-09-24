<?php

namespace Templately\Modules\ThemeBuilder\REST;

use Templately\Admin\Roles;
use Templately\API\API;
use Templately\Modules\ThemeBuilder\Types\BaseTemplate;
use WP_Error;
use WP_REST_Request;

class ThemeBuilderApi extends API {
	public function permission_check( WP_REST_Request $request ) {
		if ( $request->get_route() === '/templately/v1/create-template' ) {
			if ( ! wp_verify_nonce( $request->get_param( 'nonce' ), 'templately_nonce' ) ) {
				return false;
			}

			// `templately_library` is site-structure control, so its capability map
			// resolves every write primitive to `edit_templately_builder`. The old
			// `edit_posts` bar let the Author role create, publish and condition a
			// site-wide header/footer/archive template with no admin review.
			if ( ! Roles::can_manage_builder() ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'Sorry, you are not allowed to create templates.', 'templately' ),
					[ 'status' => rest_authorization_required_code() ]
				);
			}

			return true;
		}

		return parent::permission_check( $request );
	}

	public function register_routes() {
		$this->post( 'create-template', [ $this, 'create' ], [
			'platform' => [
				'required'          => true,
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && ( $param == 'elementor' || 'gutenberg' == $param );
				}
			],
			'type'     => [
				'required'          => true,
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && array_key_exists( $param, templately()->theme_builder::$templates_manager->get_template_types() );
				}
			],
			'title'    => [
				'required' => true,
			]
		] );
	}

	public function create( WP_REST_Request $request ) {
		$platform      = $request->get_param( 'platform' );
		$template_type = $request->get_param( 'type' );
		$title         = $request->get_param( 'title' );

		if ( empty( $title ) ) {
			$title = '';
		}

		$post_data = [
			'post_title' => $title,
			'post_status' => 'publish',
			'post_type' => 'templately_library'
		];

		/**
		 * @var BaseTemplate $template
		 */
		$template = templately()->theme_builder::$templates_manager->create( $template_type, $post_data, [ 'platform' => $platform ] );

		if ( is_wp_error( $template ) ) {
			/**
			 * @var WP_Error $template ;
			 */
			return $this->error( 'failed_template_creation', sprintf( __( 'Something went wrong. %s', 'templately' ), $template->get_error_message() ) );
		}

		return $this->success( $template->get_edit_url() );
	}
}