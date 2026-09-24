<?php

namespace Templately\Utils;

use function get_option;
use function update_option;
use function get_user_meta;
use function update_user_meta;
use function get_current_user_id;

class Options extends Base {
	/**
	 * Pin every derived read/write to the acting user, bypassing the
	 * global-login fallback in user_id().
	 *
	 * @var bool
	 */
	private $force_current_user = false;

	/**
	 * Get the current user ID.
	 *
	 * Resolved live on every call rather than cached at construction time:
	 * `Options` is a request-lifetime singleton (`Base::get_instance()`), and
	 * something in Templately's own bootstrap (e.g. `Admin`/`Settings`)
	 * constructs it during `plugins_loaded` — before WordPress resolves the
	 * REST Application-Password current user (which only happens later, when
	 * the REST server actually dispatches the route). Caching `
	 * get_current_user_id()` at construction froze it at `0` for the rest of
	 * the request on any headless (non-cookie) REST/MCP call, so every later
	 * `Options` read looked up user `0`'s meta instead of the real acting
	 * user's — reporting "session expired" even for a genuinely connected
	 * account. `get_current_user_id()` is itself cheap (it just reads WP
	 * core's own already-resolved `$current_user` global), so there is no
	 * reason to cache it a second time here.
	 *
	 * @return int
	 */
	public function current_user_id(): int {
		return get_current_user_id();
	}

	/**
	 * Whether the current user has an API key set — computed live for the
	 * same reason as {@see current_user_id()}, not cached.
	 *
	 * @return boolean
	 */
	private function has_api(): bool {
		return ! empty( $this->get( 'api_key', '', $this->current_user_id() ) );
	}

	/**
	 * Can a user link another templately account in a setup?.
	 * @return boolean
	 */
	public function link_account(): bool {
		return $this->who_am_i() === 'link' && ! $this->has_api();
	}

	public function unlink_account(): bool {
		return ( $this->who_am_i() === 'link' || $this->who_am_i() === 'local' ) && $this->has_api();
	}

	/**
	 * Get determined who am I.
	 * @return string
	 */
	public function who_am_i(): string {
		$_who_am_i = 'local';
		$current_user = $this->current_user_id();

		if( $this->is_global() > 0 && $this->is_global() === $current_user ) {
			$_who_am_i = 'global';
		}

		if( $this->is_global() > 0 && $this->is_global() !== $current_user ) {
			$_who_am_i = 'link';
		}

		if( $this->is_global() == 0 ) {
			$_who_am_i = 'local';
		}

		return $_who_am_i;
	}

	/**
	 * Pin the acting user as the target for every derived read/write.
	 *
	 * @param bool $force Whether to force the current user.
	 * @return Options
	 */
	public function use_current_user( bool $force = true ): Options {
		$this->force_current_user = $force;

		return $this;
	}

	/**
	 * Get user id determine dynamically
	 * @return integer
	 */
	private function user_id(): int {
		if ( $this->force_current_user ) {
			return $this->current_user_id();
		}

		$_who_am_i = $this->who_am_i();

		if( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			// FR-003: in 'link' mode the login-endpoint override targets the
			// current user so they can store their own credentials. Detect it
			// with a substring match on '/login' so REST paths such as
			// `/wp-json/templately/v1/login` are covered — including requests
			// with NO query string, which the former last-path-segment check
			// (substr up to '?', which collapses to '' when there is no '?')
			// never matched. `strpos(...) !== false` keeps the PHP 7.4 floor
			// (`str_contains()` is PHP 8.0+).
			$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			if( $_who_am_i === 'link' && strpos( $uri, '/login' ) !== false ) {
				return $this->current_user_id();
			}
		}

		if( $_who_am_i === 'link' && $this->has_api() ) {
			return $this->current_user_id();
		}

		return $_who_am_i === 'local' ? $this->current_user_id() : $this->is_global();
	}

	/**
	 * Globally logged in and the User ID of globally logged-in user.
	 * @return integer
	 */
	public function is_global(): int {
		return intval( get_option('_templately_global_login', 0) );
	}

	/**
	 * Set global login flag
	 * @return boolean
	 */
	public static function set_global_login(): bool {
		return update_option('_templately_global_login', get_current_user_id(), 'no');
	}

	/**
	 * Remove global login flag
	 * @return boolean
	 */
	public function remove_global_login(): bool {
		return delete_option( '_templately_global_login' );
	}

	public function is_globally_signed(): bool {
		return $this->who_am_i() !== 'local';
	}

	public function signed_as_global(): bool {
		return $this->current_user_id() === $this->is_global();
	}

	/**
	 * Set optional user meta or option data
	 *
	 * @param string $key
	 * @param mixed $value
	 * @param null $user_id
	 * @return boolean
	 */
	public function set( $key, $value, $user_id = null ): bool {
		$key = '_templately_' . $key;

		return $this->update_user_meta( $user_id, $key, $value );
	}

	/**
	 * Get optional user meta or option data
	 *
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	public function get( $key, $default = false, $user_id = null ){
		$key = '_templately_' . $key;
		$_user_meta = $this->get_user_meta( $user_id, $key, true );
		// '' (get_user_meta) and false (get_user_option) are the MISSING sentinels —
		// but 0, '0' and [] are legitimate stored values and must not collapse to
		// the default (the same falsy-swallow bug fixed in API::get_param()).
		return ( '' === $_user_meta || false === $_user_meta ) ? $default : $_user_meta;
	}

	/**
	 * Remove options data or user meta
	 *
	 * @param string $key
	 * @return Options
	 */
	public function remove( string $key ): Options {
		$key = '_templately_' . $key;
		$this->delete_user_meta( $key );

		return $this;
	}

	public function get_user_meta( $user_id, $key = '', $single = false ) {
		$user_id = is_null( $user_id ) ? $this->user_id() : $user_id;

		if( ! is_multisite() ) {
			return get_user_meta( $user_id, $key, $single);
		}

		return get_user_option( $key, $user_id );
	}

	public function update_user_meta($user_id, $meta_key, $meta_value) {
		if ( is_null( $user_id ) ) {
			if ( ! $this->can_write() ) {
				return false;
			}

			$user_id = $this->user_id();
		}

		if( ! is_multisite() ) {
			return update_user_meta( $user_id, $meta_key, $meta_value );
		}

		return update_user_option( $user_id, $meta_key, $meta_value, $this->_is_global() );
	}

	public function delete_user_meta( $meta_key ): bool {
		if ( ! $this->can_write() ) {
			return false;
		}

		if( ! is_multisite() ) {
			return delete_user_meta( $this->user_id(), $meta_key );
		}

		return delete_user_option( $this->user_id(), $meta_key, $this->_is_global() );
	}

	/**
	 * Whether the current request may write to a derived user target.
	 *
	 * Reads retain the global-login fallback for unauthenticated cloud callbacks,
	 * but an anonymous request must never write through it to the administrator.
	 *
	 * @return bool
	 */
	private function can_write(): bool {
		return $this->current_user_id() > 0;
	}

	private function _is_global() {
		return apply_filters( 'templately_multisite_is_global', false );
	}

	/**
	 * Get option data
	 *
	 * @since 2.0.1
	 *
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	public function get_option( $key, $default = false ){
		return get_option( $key, $default );
	}

	/**
	 * Update option data
	 *
	 * @since 2.0.1
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @param string $autoload
	 *
	 * @return bool
	 */
	public function update_option( $key, $value, $autoload = 'no' ): bool {
		return update_option( $key, $value, $autoload );
	}
}
