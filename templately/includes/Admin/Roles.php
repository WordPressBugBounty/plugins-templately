<?php

namespace Templately\Admin;

use Templately\Utils\Base;
use Templately\Utils\Database;
use Templately\Utils\Options;

class Roles extends Base {
	/**
	 * Capability guarding the Theme Builder: creating, editing, publishing and
	 * assigning display conditions to `templately_library` templates.
	 *
	 * A theme-builder template owns what every visitor sees in the header, footer,
	 * archive and single slots, so it is site-structure control — not authoring of
	 * one's own post content. It has always been declared administrator-only in
	 * defaults_capabilities() below; until now it was declared and never checked,
	 * and the feature gated on `delete_posts`/`publish_post`/`edit_posts` instead,
	 * all of which the Author role holds by default.
	 */
	const CAP_BUILDER = 'edit_templately_builder';

	/**
	 * Capability guarding Templately settings.
	 */
	const CAP_SETTINGS = 'edit_templately_settings';

	/**
	 * Database class
	 * @var Database
	 */
	protected $database;

	/**
	 * Options class
	 * @var Options
	 */
	protected $options;

	public function __construct() {
		$this->database = new Database();
		$this->options = new Options();
	}

	/**
	 * Runtime hooks. Registered on every request (from Maintenance::init()), not
	 * only on activation.
	 */
	public static function init() {
		add_filter( 'user_has_cap', [ __CLASS__, 'grant_caps_to_administrators' ], 10, 4 );
	}

	/**
	 * Treat `manage_options` as implying Templately's own capabilities.
	 *
	 * setup() only runs on activation, so a site that was already active before
	 * these capabilities were first wired to an enforcement point would have no
	 * administrator holding them — and every gate below would lock the site's own
	 * administrators out of the Theme Builder. Deriving them from `manage_options`
	 * removes that migration hazard entirely, and grants nothing new: a user with
	 * `manage_options` can already assign themselves any capability. Author,
	 * Contributor and Editor hold `manage_options` in no default configuration.
	 *
	 * @param array $allcaps All capabilities of the user.
	 * @param array $caps    Required primitive capabilities for the requested capability.
	 * @param array $args    [0] requested capability, [1] user ID, [2] object ID.
	 * @param \WP_User $user The user object.
	 *
	 * @return array
	 */
	public static function grant_caps_to_administrators( $allcaps, $caps, $args, $user ) {
		if ( empty( $allcaps['manage_options'] ) ) {
			return $allcaps;
		}

		$allcaps[ self::CAP_BUILDER ]  = true;
		$allcaps[ self::CAP_SETTINGS ] = true;

		return $allcaps;
	}

	/**
	 * Whether the current user may manage Theme Builder templates.
	 *
	 * The single answer every Theme Builder / display-conditions gate asks, so the
	 * bar cannot drift between the ajax handler, the REST controllers and the CPT.
	 *
	 * @param int|null $user_id Defaults to the current user.
	 *
	 * @return bool
	 */
	public static function can_manage_builder( $user_id = null ): bool {
		$can = $user_id === null
			? current_user_can( self::CAP_BUILDER )
			: user_can( $user_id, self::CAP_BUILDER );

		/**
		 * Filter the Theme Builder authorization answer.
		 *
		 * Sites that deliberately want editors (or another role) managing
		 * templates should prefer granting `edit_templately_builder` through the
		 * `templately_default_caps` filter; this is the escape hatch for gating
		 * that cannot be expressed as a role capability.
		 *
		 * @param bool     $can     Whether the user may manage theme-builder templates.
		 * @param int|null $user_id User being checked, null for the current user.
		 */
		return (bool) apply_filters( 'templately_can_manage_builder', $can, $user_id );
	}

	/**
	 * Capability map for the `templately_library` post type.
	 *
	 * Every write/list primitive resolves to CAP_BUILDER, so creating, editing,
	 * publishing and deleting a template all require it. `read` is deliberately
	 * left at the `post` default — rendering a template on the front end must not
	 * require an admin capability.
	 *
	 * @return array
	 */
	public static function builder_post_type_capabilities(): array {
		$cap = self::CAP_BUILDER;

		return [
			'create_posts'           => $cap,
			'edit_posts'             => $cap,
			'edit_others_posts'      => $cap,
			'edit_published_posts'   => $cap,
			'edit_private_posts'     => $cap,
			'publish_posts'          => $cap,
			'delete_posts'           => $cap,
			'delete_others_posts'    => $cap,
			'delete_published_posts' => $cap,
			'delete_private_posts'   => $cap,
			'read_private_posts'     => $cap,
		];
	}

	/**
	 * Default Roles Capabilities
	 *
	 * @return array
	 */
	public function defaults_capabilities(): array {
		$default_capabilities = [
			'administrator' => [
				self::CAP_BUILDER,
				self::CAP_SETTINGS
			],
			'editor'        => [
			],
			'author'        => [
			],
			'contributor'   => [
			]
		];

		return apply_filters( 'templately_default_caps', $default_capabilities );
	}

	public function setup( $remove = false ) {
		if ( $this->options->get_option( '_templately_caps_initialized', false ) && ! $remove ) {
			return;
		}

		global $wp_roles;

		$capabilities = $this->defaults_capabilities();

		if( $remove ) {
			unset( $capabilities['administrator'] );
		}

		foreach ( $capabilities as $role => $caps ) {
			foreach ( $caps as $cap ) {
				if ( $remove ) {
					$wp_roles->remove_cap( $role, $cap );
					continue;
				}

				$wp_roles->add_cap( $role, $cap );
			}
		}

		$this->options->update_option( '_templately_caps_initialized', ! $remove );
	}
}
