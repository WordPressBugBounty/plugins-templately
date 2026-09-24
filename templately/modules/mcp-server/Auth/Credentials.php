<?php
/**
 * Connection credentials for the built-in MCP server (spec 044, FR-020–FR-027).
 *
 * ## Storage — and why NOT Options
 *
 * Plain `get_option`/`update_option`, non-autoloaded. On multisite each site has
 * its own options table, so per-site scoping (FR-039a) holds by construction —
 * a credential minted on one site cannot authenticate against another.
 *
 * Deliberately NOT `Templately\Utils\Options`: that class switches to
 * `get_user_option`/`update_user_option` on multisite (Options.php:177-195),
 * scoped by an `_is_global()` flag. That is the documented cause of the defect
 * where a value written from one context is read from a different scope — the
 * project's own CLAUDE.md warns that setting the API key from WP-CLI "can land
 * in a different scope than the read". Connection state must not inherit it.
 *
 * Also NOT `get_site_option`: that is network-wide, which would make one
 * credential authenticate against every site in the network.
 *
 * ## Secrets
 *
 * Only a SHA-256 hash is stored (FR-021). The plaintext exists exactly once, in
 * the response to the create call. Presenting the stored hash does not
 * authenticate, because whatever is presented is itself hashed before
 * comparison. This corrects the reference implementation, which stores its
 * pairing token in plaintext while hashing its OAuth tokens.
 *
 * @package Templately\Modules\McpServer\Auth
 */

namespace Templately\Modules\McpServer\Auth;

use Templately\Modules\McpCore\Registry\ToolDescriptor;

class Credentials {

	const OPTION = 'templately_mcp_credentials';

	/** Throttle for last-used writes, so read traffic doesn't write every request. */
	const LAST_USED_THROTTLE = 60;

	/**
	 * All credential records (never contains a usable secret).
	 *
	 * @return array
	 */
	public static function all(): array {
		$records = get_option( self::OPTION, [] );

		return is_array( $records ) ? $records : [];
	}

	/**
	 * Admin-facing listing — FR-020c. Explicitly drops the hash so it cannot
	 * reach a response body even by accident.
	 *
	 * @return array
	 */
	public static function list_public(): array {
		return array_values(
			array_map(
				static function ( $record ) {
					unset( $record['token_hash'] );

					// Resolve the bound account to a name. An administrator needs
					// to see WHO a credential acts as — a bare user id does not
					// answer that, and this is the identity every capability's
					// permission check runs against.
					$user = ! empty( $record['user_id'] ) ? get_userdata( (int) $record['user_id'] ) : null;

					$record['user_login'] = $user ? $user->user_login : '';

					return $record;
				},
				self::all()
			)
		);
	}

	/**
	 * Mint a credential. The returned `secret` is the ONLY time it exists.
	 *
	 * @param string $name         Administrator-supplied label.
	 * @param int    $user_id      Account the credential acts as.
	 * @param string $access_level ToolDescriptor::ACCESS_READ|ACCESS_FULL.
	 * @return array{id:string,secret:string,record:array}
	 */
	public static function create( string $name, int $user_id, string $access_level ): array {
		$secret = bin2hex( random_bytes( 32 ) );
		$id     = 'cred_' . bin2hex( random_bytes( 8 ) );

		$record = [
			'id'           => $id,
			'name'         => $name !== '' ? $name : __( 'Untitled connection', 'templately' ),
			'token_hash'   => hash( 'sha256', $secret ),
			'user_id'      => $user_id,
			'access_level' => self::normalize_level( $access_level ),
			'created_at'   => time(),
			'last_used_at' => null,
		];

		$records         = self::all();
		$records[ $id ]  = $record;

		update_option( self::OPTION, $records, 'no' );

		return [ 'id' => $id, 'secret' => $secret, 'record' => $record ];
	}

	/**
	 * Resolve a presented secret to its record, or null.
	 *
	 * Compares with hash_equals against every record. N is single digits in
	 * practice, and a linear scan keeps the comparison constant-time per record.
	 *
	 * @param string $secret
	 * @return array|null
	 */
	public static function find_by_secret( string $secret ): ?array {
		if ( '' === $secret ) {
			return null;
		}

		$presented = hash( 'sha256', $secret );

		foreach ( self::all() as $record ) {
			if ( ! empty( $record['token_hash'] ) && hash_equals( (string) $record['token_hash'], $presented ) ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * @param string $id
	 * @param string $access_level
	 * @return bool
	 */
	public static function set_access_level( string $id, string $access_level ): bool {
		$records = self::all();

		if ( ! isset( $records[ $id ] ) ) {
			return false;
		}

		$records[ $id ]['access_level'] = self::normalize_level( $access_level );

		// Takes effect on the very next request — no client reconfiguration
		// needed, because the level is read per-request from this record (FR-027).
		return update_option( self::OPTION, $records, 'no' );
	}

	/**
	 * Revoke ONE credential. Every other credential keeps working (FR-020b).
	 *
	 * @param string $id
	 * @return bool
	 */
	public static function revoke( string $id ): bool {
		$records = self::all();

		if ( ! isset( $records[ $id ] ) ) {
			return false;
		}

		unset( $records[ $id ] );

		return update_option( self::OPTION, $records, 'no' );
	}

	/**
	 * Revoke everything and return the endpoint to inert (FR-024a).
	 * Also clears delegated records — one kill switch covers both credential
	 * systems, so an administrator never has to revoke twice.
	 *
	 * @return void
	 */
	public static function revoke_all(): void {
		delete_option( self::OPTION );

		if ( class_exists( OAuth\RecordStore::class ) ) {
			OAuth\RecordStore::purge_all();
		}
	}

	/**
	 * Whether the site holds any credential. While false, every request to the
	 * endpoint is refused — the endpoint is inert until an administrator
	 * explicitly connects (FR-023).
	 *
	 * @return bool
	 */
	public static function site_has_any(): bool {
		if ( ! empty( self::all() ) ) {
			return true;
		}

		return class_exists( OAuth\RecordStore::class ) && OAuth\RecordStore::has_any();
	}

	/**
	 * Record use, throttled.
	 *
	 * @param string $id
	 * @return void
	 */
	public static function touch( string $id ): void {
		$records = self::all();

		if ( ! isset( $records[ $id ] ) ) {
			return;
		}

		$last = (int) ( $records[ $id ]['last_used_at'] ?? 0 );

		if ( ( time() - $last ) < self::LAST_USED_THROTTLE ) {
			return;
		}

		$records[ $id ]['last_used_at'] = time();

		update_option( self::OPTION, $records, 'no' );
	}

	/**
	 * Anything not explicitly "read" is full access — fail closed (FR-026c).
	 *
	 * @param string $level
	 * @return string
	 */
	public static function normalize_level( string $level ): string {
		return ToolDescriptor::ACCESS_READ === $level
			? ToolDescriptor::ACCESS_READ
			: ToolDescriptor::ACCESS_FULL;
	}
}
