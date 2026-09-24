<?php

namespace Templately\Modules\DisplayConditions\Managers;

use Templately\Modules\ThemeBuilder\ThemeBuilder;
use Templately\Modules\ThemeBuilder\Types\ThemeTemplate;
use Templately\Modules\DisplayConditions\Conditions\Condition;

class ConditionManager {
	const META_KEY  = '_templately_conditions';

	private $cache;

	private $locations = [];

	/**
	 * @var Condition[]
	 */
	private $conditions = [];

	/**
	 * @var ThemeBuilder
	 */
	private $builder;

	private $location_cache = [];

	public function __construct( $builder ) {
		$this->cache   = new Cache();
		$this->builder = $builder;

		add_action( 'wp_loaded', [ $this, 'register' ] );

		// The three `wp_ajax_templately_*conditions*` actions that used to be
		// registered here were already commented out. Their handlers
		// (`get_conditions()`, `check_conditions()`) and the `verify_nonce()` helper
		// were removed with them (spec 043 / PRD PHP-5): `verify_nonce()` was
		// hardcoded to `return true` with the real check commented out, so
		// re-enabling any of those lines would have exposed an unauthenticated
		// endpoint. Conditions are read and written through
		// `modules/display-conditions/REST/Conditions.php`, which is nonce- and
		// capability-checked by the REST framework.
	}

	public function get_conditions_for_display( $template_id = null ): array {
		if ( ! is_numeric( $template_id ) ) {
			$template_id = null;
		}

		$config = [];
		foreach ( $this->conditions as $condition ) {
			$config[ $condition->get_name() ] = $condition->get_config();
		}

		if ( ! $template_id ) {
			return [
				'config' => $config
			];
		}

		$template        = $this->builder::$templates_manager->get( $template_id );
		$conditions_meta = $template->get_meta( self::META_KEY );

		$conditions_for_display = [];

		if ( is_array( $conditions_meta ) && ! empty( $conditions_meta ) ) {
			foreach ( $conditions_meta as $c ) {
				$conditions_for_display[] = $this->parse_condition( $c );
			}
		}


		return [
			'config'              => $config,
			'template_conditions' => $conditions_for_display
		];
	}

	public function save_conditions( $post_id, $conditions = [] ): bool {
		$conditions_to_save = [];
		foreach ( $conditions as $condition ) {
			if ( is_string( $condition ) ) {
				$conditions_to_save[] = rtrim( $condition, '/' );
				continue;
			}

			unset( $condition['id'] );
			$conditions_to_save[] = rtrim( implode( '/', $condition ), '/' );
		}

		/**
		 * @var ThemeTemplate $template
		 */
		$template = $this->builder->get_template( $post_id );

		if ( empty( $conditions_to_save ) ) {
			$template->delete_meta( self::META_KEY );
			$this->cache->remove( $post_id )->save();
		} else {
			$template->update_meta( self::META_KEY, $conditions );
			$this->cache->add( $template, $conditions )->save();
		}

		$this->cache->regenerate();

		// Conditions just changed → the in-memory per-location winners are stale. Drop them
		// so a subsequent get_templates_by_location() in the same request re-resolves against
		// the new conditions (otherwise the first-call cache wins and ignores the edit).
		$this->location_cache = [];

		return true;
	}

	public function register() {
		$this->register_condition( 'general' );
	}

	public function register_condition( string $id, $args = [] ) {
		if ( isset( $this->conditions[ $id ] ) ) {
			return;
		}

		// if ( strpos( $id, '_' ) !== false ) {
		// 	$id = implode( '', array_map( 'ucfirst', explode( '_', $id ) ) );
		// }

		$class_name = '\\Templately\\Modules\\DisplayConditions\\Conditions\\' . ucfirst( $id );
		/** @var Condition $condition */
		$condition = new $class_name( $args );
		$this->register_condition_instance( $condition );

		foreach ( $condition->get_sub_conditions() as $key => $_sub_id ) {
			if ( is_numeric( $key ) ) {
				$id   = $_sub_id;
				$args = [];
			} else {
				$id   = $key;
				$args = $_sub_id;
			}
			$this->register_condition( $_sub_id, $args );
		}
	}

	/**
	 * @param Condition $object
	 */
	public function register_condition_instance( Condition $object ) {
		$this->conditions[ $object->get_name() ] = $object;
	}

	public function get_templates_by_location( $location ): array {
		if ( isset( $this->location_cache[ $location ] ) ) {
			return $this->location_cache[ $location ];
		}

		$template_ids = $this->get_template_ids( $location );

		//		dump( $location, $template_ids );

		$templates = [];

		$location_settings = templately()->theme_builder::$location_manager->get_location( $location );

		if ( ! empty( $template_ids ) ) {
			foreach ( $template_ids as $template_id => $priority ) {
				$template = $this->builder::$templates_manager->get( $template_id );

				if ( $template ) {
					$templates[ $template_id ] = $template;
				}

				if ( empty( $location_settings['multiple'] ) ) {
					break;
				}
			}
		}

		$this->location_cache[ $location ] = $templates;

		return $templates;
	}

	private function get_template_ids( $location ): array {
		$current_post_id = get_the_ID();
		$template        = $this->builder->get_template( $current_post_id );
		if ( $template && $location === $template->get_location() ) {
			return [ $current_post_id => 1 ];
		}

		return $this->get_location_templates( $location );
	}

	public function get_location_templates( string $location ): array {
		$conditions_priority = [];
		$conditions_groups   = $this->cache->get_by_location( $location );

		if ( empty( $conditions_groups ) ) {
			return $conditions_priority;
		}


		$excludes = [];

		//		dump( $conditions_groups );

		foreach ( $conditions_groups as $template_id => $conditions ) {
			foreach ( $conditions as $condition ) {
				$origin_c = $condition;

				extract( $this->parse_condition( $condition ) );

				$is_include = $type === 'include';
				$condition  = $this->get_condition( $name );

				if ( ! $condition ) {
					continue;
				}

				$is_passed     = $condition->check();
				$sub_condition = null;


				if ( $is_passed && $sub_name ) {
					$sub_condition = $this->get_condition( $sub_name );


					if ( ! $sub_condition ) {
						continue;
					}

					$is_passed = $sub_condition->check( [ 'id' => $sub_id ] );
				}

				if ( $is_passed ) {
					// Get the post ID in the current language
					if($template_id_locale = apply_filters( 'wpml_object_id', $template_id )){
						$template_id = $template_id_locale;
					}

					$post_status = get_post_status( $template_id );

					if ( $post_status !== 'publish' ) {
						continue;
					}

					if ( $is_include ) {
						$conditions_priority[ $template_id ] = $this->get_priority( $condition, $sub_condition, $sub_id );
					} else {
						$excludes[] = $template_id;
					}
				}
			}
		}

		foreach ( $excludes as $exclude_id ) {
			unset( $conditions_priority[ $exclude_id ] );
		}

		$this->sort_by_priority( $conditions_priority );

		return $conditions_priority;
	}

	/**
	 * Sort `[template_id => priority]` by priority ascending (lower priority
	 * value = more specific condition = wins — unchanged from the previous
	 * `asort()` behavior), with a deterministic tie-break on EQUAL priority:
	 * the lowest post/template ID wins, regardless of insertion order.
	 *
	 * `asort()` is stable-by-insertion-order for equal values, so which
	 * template won a tie used to depend on iteration order over
	 * `$conditions_groups` (effectively arbitrary — see spec 034 SC-003).
	 *
	 * The tie itself is now settled by active flag, then recency, then ID. Lowest-ID
	 * alone meant the OLDEST template won, so a newly imported header could never
	 * displace the one a previous import left behind. Still deterministic — the
	 * property SC-003 actually cares about — just no longer ordered by age ascending.
	 *
	 * @param array $conditions_priority [template_id => priority], sorted in place.
	 */
	/** Memoized per request — `usort` asks the same question many times. */
	private static $active_cache = [];
	private static $modified_cache = [];

	/** Whether a template carries the active flag for its type. */
	private static function is_active_template( $template_id ): bool {
		if ( ! isset( self::$active_cache[ $template_id ] ) ) {
			self::$active_cache[ $template_id ] =
				'1' === (string) get_post_meta( (int) $template_id, '_templately_is_active_template', true );
		}

		return self::$active_cache[ $template_id ];
	}

	/** A template's last-modified time, as a comparable integer. */
	private static function template_modified( $template_id ): int {
		if ( ! isset( self::$modified_cache[ $template_id ] ) ) {
			$post = get_post( (int) $template_id );
			self::$modified_cache[ $template_id ] =
				( $post && ! empty( $post->post_modified_gmt ) ) ? (int) strtotime( $post->post_modified_gmt ) : 0;
		}

		return self::$modified_cache[ $template_id ];
	}

	private function sort_by_priority( array &$conditions_priority ) {
		$entries = [];
		foreach ( $conditions_priority as $template_id => $priority ) {
			$entries[] = [ $template_id, $priority ];
		}

		usort( $entries, function ( $a, $b ) {
			if ( $a[1] === $b[1] ) {
				// Tie: same location, same specificity. Conditions have said all they can,
				// so fall through three rules, each fully deterministic.

				// 1. The template marked active for its type. This is the whole reason the
				//    flag exists: an imported pack's header must take over from the one a
				//    previous import left behind.
				$a_active = self::is_active_template( $a[0] );
				$b_active = self::is_active_template( $b[0] );

				if ( $a_active !== $b_active ) {
					return $a_active ? -1 : 1;
				}

				// 2. The more recently modified one. Covers every site that predates the
				//    flag, where nothing is marked at all — and "the one I touched last"
				//    is a far better guess than "the one I imported first".
				$a_time = self::template_modified( $a[0] );
				$b_time = self::template_modified( $b[0] );

				if ( $a_time !== $b_time ) {
					return $b_time <=> $a_time;
				}

				// 3. Lowest post ID, so two templates saved in the same second still
				//    resolve the same way on every request.
				return $a[0] <=> $b[0];
			}

			// Lower priority value = more specific condition = wins.
			return $a[1] <=> $b[1];
		} );

		$conditions_priority = [];
		foreach ( $entries as $entry ) {
			$conditions_priority[ $entry[0] ] = $entry[1];
		}
	}

	private function get_condition( $name ) {
		return $this->conditions[ $name ] ?? null;
	}

	/**
	 * @param $condition
	 * @param $sub_condition
	 * @param $sub_id
	 *
	 * @return int|mixed
	 */
	private function get_priority( $condition, $sub_condition, $sub_id ) {
		$priority = $condition->get_priority();

		if ( $sub_condition ) {
			if ( $sub_condition->get_priority() < $priority ) {
				$priority = $sub_condition->get_priority();
			}

			$priority -= 10;

			if ( $sub_id ) {
				$priority -= 10;
			} elseif ( 0 === count( $sub_condition->get_sub_conditions() ) ) {
				$priority -= 5;
			}
		}

		return $priority;
	}

	protected function parse_condition( $condition ): array {
		if ( is_string( $condition ) ) {
			list( $type, $name, $sub_name, $sub_id ) = array_pad( explode( '/', $condition ), 4, '' );
			$condition = compact( 'type', 'name', 'sub_name', 'sub_id' );
		}

		return $condition;
	}

}