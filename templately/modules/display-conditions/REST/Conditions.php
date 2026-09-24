<?php

namespace Templately\Modules\DisplayConditions\REST;

use Templately\Admin\Roles;
use Templately\API\API;
use Templately\Utils\Response\ErrorCode;
use Templately\Modules\DisplayConditions\Managers\ConditionManager;
use WP_REST_Request;
use WP_REST_Response;

class Conditions extends API {
	/**
	 * @var ConditionManager
	 */
	private $conditions_manager;

	public function permission_check( WP_REST_Request $request ) {
		// Assigning display conditions is what actually activates a template
		// site-wide, so it carries the same administrator-only bar as creating one.
		// The old check resolved to `edit_posts`, which the Author role holds.
		return Roles::can_manage_builder();
	}

	public function register_routes() {
		$this->get( 'conditions', [ $this, 'get_conditions' ], [
			'template_id' => [
				'required'          => false,
				'validate_callback' => function ( $param ) {
					return empty( $param ) || is_numeric( $param );
				}
			]
		] );

		$this->get( 'check-conditions', [ $this, 'check' ], [
			'template_id' => [
				'required'          => true,
				'validate_callback' => function ( $param ) {
					return is_numeric( $param );
				}
			]
		] );

		$this->post( 'save-conditions', [ $this, 'save' ], [
			'template_id' => [
				'required'          => true,
				'validate_callback' => function ( $param ) {
					return is_numeric( $param );
				}
			],
			'conditions'  => [
				'required'          => true,
				'validate_callback' => function ( $param ) {
					return is_array( $param );
				}
			]
		] );

		$this->get( 'autocomplete-condition', [ $this, 'autocomplete' ], [
			'payload' => [
				'required'          => true,
				'validate_callback' => function ( $param ) {
					return is_string( $param );
				}
			],
			'query'   => [
				'required'          => true,
				'validate_callback' => function ( $param ) {
					return is_array( $param ) && array_key_exists( 'query_type', $param );
				}
			]
		] );

		$this->conditions_manager = templately()->theme_builder::$conditions_manager;
	}

	public function get_conditions( WP_REST_Request $request ): WP_REST_Response {
		$conditions = $this->conditions_manager->get_conditions_for_display( $request->get_param( 'template_id' ) );

		return $this->success( $conditions );
	}

	public function check( WP_REST_Request $request ): WP_REST_Response {
		return $this->success( [] );
	}

	public function save( WP_REST_Request $request ): WP_REST_Response {
		$conditions = rest_sanitize_array( $request->get_param( 'conditions' ) );
		$id         = (int) $request->get_param( 'template_id' );


		$this->conditions_manager->save_conditions( $id, $conditions );


		return $this->success( __( 'Successfully saved.', 'templately' ) );
	}

	public function autocomplete( WP_REST_Request $request ): WP_REST_Response {
		$query = $request->get_param( 'query' );
		$type  = $query['query_type'] ?? '';

		$allowed_fields = [
			'authors'  => [ 'ID', 'user_nicename', 'display_name' ],
			'posts'    => [ 'ID', 'post_title', 'post_name' ],
			'taxonomy' => [ 'term_id', 'slug', 'name' ],
		];

		// These three used to answer `success([])`, which is indistinguishable from
		// "searched fine, found nothing". A rejected query and an empty result set
		// are different facts, and the client could not tell them apart — so a
		// malformed request looked like an empty condition list.
		if ( empty( $type ) || ! isset( $allowed_fields[ $type ] ) ) {
			return $this->error(
				ErrorCode::INVALID_REQUEST,
				__( 'Unsupported query type.', 'templately' ),
				'conditions_query',
				400
			);
		}

		$by_field = $query['field'] ?? '';

		if ( empty( $by_field ) || ! in_array( $by_field, $allowed_fields[ $type ], true ) ) {
			return $this->error(
				ErrorCode::INVALID_REQUEST,
				__( 'Unsupported query field.', 'templately' ),
				'conditions_query',
				400
			);
		}

		// A capability failure is a 403, not an empty list. Returning success here
		// hid the refusal from the user AND from anyone auditing access denials.
		if ( 'authors' === $type && ! current_user_can( 'list_users' ) ) {
			return $this->error(
				ErrorCode::AUTH_EXPIRED,
				__( 'You are not allowed to list users.', 'templately' ),
				'conditions_query',
				403
			);
		}

		$payload = sanitize_text_field( $request->get_param( 'payload' ) );
		$args    = [ 'search' => $payload ];
		if ( is_numeric( $payload ) ) {
			$args = [ 'post__in' => [ (int) $payload ] ];
		}

		if ( isset( $query['query'] ) && is_array( $query['query'] ) ) {
			$safe_query_keys = [
				'post_type', 'posts_per_page', 'number', 'orderby', 'order',
				'taxonomy', 'parent', 'hide_empty',
			];
			$safe_query = array_intersect_key( $query['query'], array_flip( $safe_query_keys ) );
			$args       = wp_parse_args( $safe_query, $args );
		}

		$results  = [];
		$data     = [];
		$data_key = '';

		switch ( $type ) {
			case 'taxonomy':
				$_default = [ 'hide_empty' => false ];
				$data     = get_terms( wp_parse_args( $args, $_default ) );
				$data_key = 'name';
				break;
			case 'posts':
				$args['s']           = $args['search'];
				$args['post_status'] = 'publish';
				$args['perm']        = 'readable';
				$data                = get_posts( $args );
				$data_key            = 'post_title';
				break;
			case 'authors':
				$args['search_columns'] = [ 'user_nicename', 'user_login' ];
				$args['search']         = "*{$args['search']}*";
				$data                   = get_users( $args );

				$data_key = 'display_name';
				break;
		}

		if ( ! empty( $data ) && is_array( $data ) ) {
			foreach ( $data as $item ) {
				$results[] = [
					'label' => $item->{$data_key},
					'value' => $item->{$by_field}
				];
			}
		}

		return $this->success( $results );
	}
}