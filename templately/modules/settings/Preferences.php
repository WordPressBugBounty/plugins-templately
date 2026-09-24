<?php

namespace Templately\Modules\Settings;

/**
 * Per-user UI preferences.
 *
 * Deliberately NOT stored through Utils\Options: that abstraction carries
 * global-login fallback semantics (a linked user's derived writes can land on
 * the global administrator's record), which is correct for account data and
 * wrong for personal UI taste. These are plain per-user, per-site options via
 * update_user_option()/get_user_option() — the same multisite-safe scoping,
 * no account indirection.
 */
class Preferences {

	/**
	 * Where the template modal lands when it opens.
	 * 'auto' = context rules (empty editor → pages, content → blocks, admin → packs),
	 * 'last' = last visited tab, or a fixed tab.
	 */
	const EDITOR_DEFAULT_TAB_KEY = 'templately_editor_default_tab';

	const ALLOWED_TABS = [ 'auto', 'last', 'packs', 'pages', 'blocks' ];

	public static function get_editor_default_tab( $user_id = 0 ): string {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$value   = get_user_option( self::EDITOR_DEFAULT_TAB_KEY, $user_id );

		return in_array( $value, self::ALLOWED_TABS, true ) ? $value : 'auto';
	}

	public static function set_editor_default_tab( $value, $user_id = 0 ) {
		if ( ! in_array( $value, self::ALLOWED_TABS, true ) ) {
			return false;
		}

		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		update_user_option( $user_id, self::EDITOR_DEFAULT_TAB_KEY, $value );

		return true;
	}

	/**
	 * Apply an allow-listed preferences bag. Unknown keys and invalid values are
	 * skipped, not errors — the response reports what was actually applied.
	 *
	 * @param array $preferences e.g. [ 'editor_default_tab' => 'blocks' ]
	 * @return array Applied key => value pairs.
	 */
	public static function update( array $preferences ): array {
		$applied = [];

		if ( isset( $preferences['editor_default_tab'] )
			&& self::set_editor_default_tab( sanitize_key( $preferences['editor_default_tab'] ) ) ) {
			$applied['editor_default_tab'] = sanitize_key( $preferences['editor_default_tab'] );
		}

		return $applied;
	}
}
