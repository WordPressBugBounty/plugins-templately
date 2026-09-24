<?php

namespace Templately\Modules\DisplayConditions\Conditions;

class Singular extends Condition {
	protected $sub_conditions = [
		'front',
		'page',
		'error'
	];

	public function get_type(): string {
		return 'singular';
	}

	public function get_priority(): int {
		return 60;
	}

	public function get_label(): string {
		return __( 'Singular', 'templately' );
	}

	public function get_all_label(): string {
		return __( 'All Singular', 'templately' );
	}

	public function get_name(): string {
		return 'singular';
	}

	public function check( $args = [] ): bool {
		return ( is_singular() && ! is_embed() ) || is_404();
	}

	protected function register_sub_conditions() {
		$post_types = $this->builder->get_public_post_types();

		foreach ( $post_types as $post_type => $label ) {
			// Do NOT gate on get_post_type_archive_link() here — that guard belongs to
			// Archive (an archive only exists when the CPT publishes one). A SINGLE
			// entry exists regardless of has_archive, so gating it wrongly excluded
			// has_archive=false CPTs (e.g. testimonials, team members) from ever having
			// a Single template. Skip only post types already registered via the static
			// $sub_conditions list ('page' → the Page condition) to avoid a duplicate.
			if ( in_array( $post_type, $this->sub_conditions, true ) ) {
				continue;
			}

			// A post type only belongs here if it actually has viewable single pages.
			// Cheap before ThemeBuilder gated on `show_in_nav_menus`; load-bearing now
			// that it gates on `public`, which admits post types with no front-end
			// single view at all.
			if ( ! is_post_type_viewable( $post_type ) ) {
				continue;
			}

			$condition = new Post( [
				'post_type' => $post_type,
			] );

			$this->register_sub_condition( $condition );
		}
	}
}