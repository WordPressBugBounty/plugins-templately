<?php
/**
 * Shared permission gate for all Templately MCP abilities (FR-007).
 *
 * @package Templately\Modules\McpCore\Support
 */

namespace Templately\Modules\McpCore\Support;

class Permissions {

	/**
	 * `manage_options` gate used by every ability's permission_callback,
	 * including the two read-only discovery/detail abilities.
	 *
	 * @return bool
	 */
	public static function can_use_abilities(): bool {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/**
	 * Additional gate for the "existing post" import target (FR-008):
	 * the acting user must also be able to edit the specific target post.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function can_edit_post( int $post_id ): bool {
		return get_post( $post_id ) instanceof \WP_Post && current_user_can( 'edit_post', $post_id );
	}
}
