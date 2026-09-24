<?php
/**
 * Storage for delegated-approval records (spec 044, FR-030, FR-033, FR-036,
 * FR-037, FR-042).
 *
 * ## One option row PER RECORD — the reason there is no lock
 *
 * Registered clients, approval grants and issued credentials each get their own
 * non-autoloaded option row, keyed by a hash, so the RECORDS themselves are
 * never subject to a lost update. That matters because WordPress offers no
 * portable row-locking primitive: `wp_cache_add` is unreliable without a
 * persistent object cache, and MySQL's `GET_LOCK` is unavailable on some hosts.
 * The reference implementation keeps clients, codes and tokens in ONE option
 * under read-modify-write with no locking, so two clients registering and
 * exchanging concurrently lose a write.
 *
 * ## …but `templately_mcp_oauth_index` IS shared mutable state
 *
 * An earlier version of this note claimed the read-modify-write cycle had been
 * "removed rather than defended". That was wrong: the index is one option, and
 * `put()`/`deindex()`/`sweep()` all read-modify-write it without a lock.
 *
 * The dangerous part was the ASYMMETRY. Authentication resolves a token by
 * reading its option key directly, while revocation walked only the index — so
 * a lost index update produced a token row that still authenticated but that
 * "Revoke" could not see or delete, and the UI reported success over a
 * connection that kept working.
 *
 * So: the index is a CACHE that makes the sweep cheap. Anything
 * security-relevant — revoking, or deciding whether the site holds a credential
 * at all — goes through `option_names()`, which asks the database.
 *
 * Lookup is O(1): a presented secret is hashed and the row key derived from the
 * hash, so nothing is scanned.
 *
 * @package Templately\Modules\McpServer\Auth\OAuth
 */

namespace Templately\Modules\McpServer\Auth\OAuth;

use Templately\Modules\McpCore\Registry\ToolDescriptor;

class RecordStore {

	const CLIENT_PREFIX  = 'templately_mcp_client_';
	const GRANT_PREFIX   = 'templately_mcp_grant_';
	const TOKEN_PREFIX   = 'templately_mcp_token_';
	/** Tombstones for spent refresh tokens — a hash and a chain id, never a secret. */
	const SPENT_PREFIX   = 'templately_mcp_spent_';
	const INDEX_OPTION   = 'templately_mcp_oauth_index';
	const SWEEP_HOOK     = 'templately_mcp_oauth_sweep';

	const GRANT_TTL   = 60;
	const ACCESS_TTL  = 3600;
	const REFRESH_TTL = 2592000;
	const CLIENT_TTL  = 2592000;

	// ------------------------------------------------------------- clients

	/**
	 * @param string $client_name
	 * @param array  $redirect_uris
	 * @return array
	 */
	public static function create_client( string $client_name, array $redirect_uris ): array {
		$client_id = 'tmpl_' . bin2hex( random_bytes( 16 ) );

		$record = [
			'client_id'     => $client_id,
			// Self-reported and untrusted — escaped wherever it is displayed.
			'client_name'   => $client_name,
			'redirect_uris' => array_values( $redirect_uris ),
			'created_at'    => time(),
			// An EXPLICIT expiry, because sweep() only reclaims index entries
			// whose expiry is > 0. Without it a client row registered by an
			// unauthenticated caller was immortal: read-time expiry never fires
			// for a record nobody ever reads back, so garbage registrations
			// accumulated forever and made the index grow without bound.
			'expires_at'    => time() + self::CLIENT_TTL,
		];

		self::put( self::CLIENT_PREFIX . $client_id, $record );

		return $record;
	}

	/**
	 * @param string $client_id
	 * @return array|null
	 */
	public static function get_client( string $client_id ): ?array {
		return self::get( self::CLIENT_PREFIX . $client_id, self::CLIENT_TTL );
	}

	// -------------------------------------------------------------- grants

	/**
	 * @param array $grant
	 * @return string The one-time code (never stored in plaintext).
	 */
	public static function create_grant( array $grant ): string {
		$code = bin2hex( random_bytes( 32 ) );

		$grant['created_at'] = time();

		// Defaulted HERE, not left to the caller. `put()` indexes a record with no
		// `expires_at` as expiry 0, and `sweep()` only reclaims entries whose
		// expiry is > 0 — so a grant that is abandoned mid-flow (the user opens the
		// approval screen, approves, and the client never exchanges the code) would
		// be immortal. Read-time expiry never fires for a row nobody reads back.
		// That is the same trap `create_client()` was fixed for, and it also keeps
		// `has_any()` — which counts grant rows — reporting the site as connected
		// forever, holding the endpoint out of its inert state (FR-023).
		if ( empty( $grant['expires_at'] ) ) {
			$grant['expires_at'] = time() + self::GRANT_TTL;
		}

		self::put( self::GRANT_PREFIX . hash( 'sha256', $code ), $grant );

		return $code;
	}

	/**
	 * Fetch a grant AND DELETE IT, whether or not the caller then accepts it.
	 *
	 * Deleting before verification is deliberate (FR-033): it makes a failed
	 * exchange unretryable, so a leaked code cannot be brute-forced against the
	 * proof-of-origination check.
	 *
	 * @param string $code
	 * @return array|null
	 */
	public static function consume_grant( string $code ): ?array {
		$key   = self::GRANT_PREFIX . hash( 'sha256', $code );
		$grant = self::get( $key, self::GRANT_TTL );

		delete_option( $key );
		self::deindex( $key );

		return $grant;
	}

	// -------------------------------------------------------------- tokens

	/**
	 * @param array $token
	 * @param int   $ttl
	 * @return string The secret (never stored in plaintext).
	 */
	public static function create_token( array $token, int $ttl ): string {
		$secret = bin2hex( random_bytes( 32 ) );

		$token['created_at'] = time();
		$token['expires_at'] = time() + $ttl;

		self::put( self::TOKEN_PREFIX . hash( 'sha256', $secret ), $token );

		return $secret;
	}

	/**
	 * @param string $secret
	 * @return array|null
	 */
	public static function find_access_token( string $secret ): ?array {
		$record = self::get( self::TOKEN_PREFIX . hash( 'sha256', $secret ), self::ACCESS_TTL );

		if ( null === $record || 'access' !== ( $record['type'] ?? '' ) ) {
			return null;
		}

		// Audience check — RFC 9700 §2.3 / MCP: a resource server MUST refuse a
		// token that was not meant for it. Exactly one acceptable value; the
		// transitional arm that also accepted the issuer is gone, because the
		// issuer is now path-scoped and no token ever carried that string.
		if ( ! empty( $record['resource'] ) && $record['resource'] !== self::audience() ) {
			return null;
		}

		return $record;
	}

	/**
	 * @param string $secret
	 * @return array|null
	 */
	public static function find_refresh_token( string $secret ): ?array {
		$record = self::get( self::TOKEN_PREFIX . hash( 'sha256', $secret ), self::REFRESH_TTL );

		if ( null === $record || 'refresh' !== ( $record['type'] ?? '' ) ) {
			return null;
		}

		// Same audience check as the access token. Not exploitable today (a
		// refresh token can never be presented as a bearer), but the asymmetry
		// becomes live the moment this store is shared across sites.
		if ( ! empty( $record['resource'] ) && $record['resource'] !== self::audience() ) {
			return null;
		}

		return $record;
	}

	// ------------------------------------------------- grant chains (reuse)

	/**
	 * Remember that a refresh token was spent, WITHOUT keeping the credential.
	 *
	 * Rotation alone cannot detect replay: once the old row is deleted, a stolen
	 * token presented afterwards is indistinguishable from a random string, so
	 * the attacker's theft is invisible and the legitimate client simply falls
	 * off. Keeping a tombstone — the hash and the chain it belonged to, no
	 * secret — is what makes the second presentation recognisable as REUSE.
	 *
	 * OAuth 2.1 §4.3.1 describes revoking "the active refresh token as well as
	 * the access authorization grant associated with it" on detection. (RFC 9700
	 * §4.14.2 revokes only the active token — the MUST is on having a detection
	 * mechanism at all, not on the breadth of the response. This takes the
	 * stronger of the two.)
	 *
	 * @param string $hash  sha256 of the spent secret.
	 * @param string $chain Grant chain the token belonged to.
	 * @param int    $ttl
	 * @return void
	 */
	public static function tombstone_refresh( string $hash, string $chain, int $ttl ): void {
		self::put(
			self::SPENT_PREFIX . $hash,
			[
				'chain'      => $chain,
				'created_at' => time(),
				'expires_at' => time() + $ttl,
			]
		);
	}

	/**
	 * @param string $secret
	 * @return string '' when this token was never spent.
	 */
	public static function spent_chain( string $secret ): string {
		$record = self::get( self::SPENT_PREFIX . hash( 'sha256', $secret ), self::REFRESH_TTL );

		return is_array( $record ) ? (string) ( $record['chain'] ?? '' ) : '';
	}

	/**
	 * Revoke every credential descended from one authorization grant.
	 *
	 * @param string $chain
	 * @return int Number of records removed.
	 */
	public static function revoke_chain( string $chain ): int {
		if ( '' === $chain ) {
			return 0;
		}

		$removed = 0;

		foreach ( array_merge(
			self::option_names( self::TOKEN_PREFIX ),
			self::option_names( self::SPENT_PREFIX )
		) as $key ) {
			$record = get_option( $key, null );

			if ( ! is_array( $record ) || (string) ( $record['chain'] ?? '' ) !== $chain ) {
				continue;
			}

			delete_option( $key );
			self::deindex( $key );

			++$removed;
		}

		return $removed;
	}

	/**
	 * @return string
	 */
	public static function new_chain(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Take a refresh token AND spend it, letting the DELETE arbitrate the race.
	 *
	 * `delete_option()` returns false when the row was already gone, and its
	 * underlying DELETE reports rows-affected — so of two concurrent refreshes
	 * presenting the same token, exactly one sees `true` and may mint. The
	 * previous find-then-delete let BOTH pass the lookup and both issue a token
	 * pair, leaving an extra live credential per race.
	 *
	 * @param string $secret
	 * @return array|null The record, or null if it was not ours to spend.
	 */
	public static function consume_refresh_token( string $secret ): ?array {
		$key    = self::TOKEN_PREFIX . hash( 'sha256', $secret );
		$record = self::get( $key, self::REFRESH_TTL );

		if ( null === $record || 'refresh' !== ( $record['type'] ?? '' ) ) {
			return null;
		}

		if ( ! empty( $record['resource'] ) && $record['resource'] !== self::audience() ) {
			return null;
		}

		if ( ! delete_option( $key ) ) {
			return null;
		}

		self::deindex( $key );

		return $record;
	}

	/**
	 * @param string $secret
	 * @return void
	 */
	public static function delete_token( string $secret ): void {
		self::delete_token_by_hash( hash( 'sha256', $secret ) );
	}

	/**
	 * For a token known only by its hash — the paired access token recorded on a
	 * refresh token, which is stored hashed and never held in plaintext.
	 *
	 * @param string $hash
	 * @return void
	 */
	public static function delete_token_by_hash( string $hash ): void {
		$key = self::TOKEN_PREFIX . $hash;

		delete_option( $key );
		self::deindex( $key );
	}

	/**
	 * This authorization server's ISSUER identifier (RFC 8414 §2).
	 *
	 * PATH-SCOPED — `https://site.test/templately`, not the bare origin.
	 *
	 * RFC 8414 gives an origin exactly ONE authorization-server document, at
	 * `/.well-known/oauth-authorization-server`. Claiming the bare origin as our
	 * issuer therefore put us in a single global slot that any other OAuth-
	 * serving plugin on the same site claims too — whoever registered its
	 * rewrite first won, and a client could be handed the wrong server's
	 * authorization endpoint. We won that race by accident, not by design.
	 *
	 * §3.1 resolves it: for an issuer with a path component, the metadata URL is
	 * built by INSERTING the well-known segment between host and path —
	 * `https://site.test/.well-known/oauth-authorization-server/templately`. That
	 * is a slot only this plugin can occupy, so two MCP plugins can coexist.
	 *
	 * @return string
	 */
	public static function issuer(): string {
		return untrailingslashit( home_url( '/templately' ) );
	}

	/**
	 * @deprecated Use issuer() (identity) or audience() (what a token is for).
	 *             Kept because the two were conflated under this one name.
	 * @return string
	 */
	public static function resource_id(): string {
		return self::issuer();
	}

	/**
	 * The AUDIENCE a token is minted for — the MCP endpoint's canonical URI.
	 *
	 * Distinct from the issuer above, which it used to be conflated with. Both
	 * were `resource_id()`, so the "audience check" in find_access_token() was
	 * really an issuer check that happened to be self-consistent. RFC 8707 binds
	 * a token to a RESOURCE, and RFC 9700 §2.3 makes rejecting a token meant for
	 * a different resource a MUST at the resource server — which is
	 * unimplementable while the two identifiers are the same string.
	 *
	 * This is also the only value this server will accept in a client's
	 * `resource` parameter.
	 *
	 * @return string
	 */
	public static function audience(): string {
		return rest_url( 'templately/v1/mcp' );
	}

	/**
	 * Whether a client-supplied `resource` indicator names THIS server.
	 *
	 * MCP requires clients to send `resource` on both the authorization and the
	 * token request. Accepting one that names somebody else's server and issuing
	 * a token anyway is how a client ends up believing it holds a credential for
	 * a resource it does not — the token-passthrough shape the MCP security
	 * guidance calls out.
	 *
	 * An ABSENT indicator is accepted: RFC 8707 §2.1 leaves that to local policy,
	 * and this server has exactly one resource, so there is nothing to disambiguate.
	 *
	 * @param string $requested
	 * @return bool
	 */
	public static function resource_matches( string $requested ): bool {
		if ( '' === $requested ) {
			return true;
		}

		$normalise = static function ( $value ) {
			$parts = wp_parse_url( untrailingslashit( $value ) );

			if ( empty( $parts['host'] ) ) {
				return '';
			}

			return strtolower( $parts['scheme'] ?? '' ) . '://' . strtolower( $parts['host'] )
				. ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
				. ( $parts['path'] ?? '' );
		};

		return '' !== $normalise( $requested ) && $normalise( $requested ) === $normalise( self::audience() );
	}

	// ----------------------------------------------------------- admin view

	/** Settings-list id prefix, so one revoke route can serve both systems. */
	const PUBLIC_ID_PREFIX = 'oauth_';

	/**
	 * Delegated connections for the Settings → AI Agents list, grouped BY CLIENT.
	 *
	 * Grouping matters. A live connection is a rotating pair of rows — an access
	 * token that expires hourly and a refresh token that replaces it — so
	 * listing raw token rows would show a connection vanishing an hour after it
	 * was made and reappearing under a new id. One row per application is what
	 * an administrator actually means by "the ChatGPT connection".
	 *
	 * Before this, the settings list read only `Credentials` (pairing tokens),
	 * so a site whose only connection was granted through OAuth showed an empty
	 * list and no way to inspect or revoke it.
	 *
	 * Shaped like `Credentials::list_public()` so the same table renders both.
	 *
	 * @return array
	 */
	public static function list_connections(): array {
		$clients = [];

		foreach ( self::option_names( self::TOKEN_PREFIX ) as $key ) {

			$record = get_option( $key, null );

			if ( ! is_array( $record ) || empty( $record['client_id'] ) ) {
				continue;
			}

			$expires = (int) ( $record['expires_at'] ?? 0 );

			if ( $expires > 0 && $expires < time() ) {
				continue;
			}

			$client_id = (string) $record['client_id'];
			$created   = (int) ( $record['created_at'] ?? 0 );

			if ( ! isset( $clients[ $client_id ] ) ) {
				$clients[ $client_id ] = [
					'user_id'      => (int) ( $record['user_id'] ?? 0 ),
					'access_level' => (string) ( $record['access_level'] ?? '' ),
					'created_at'   => $created,
					'expires_at'   => $expires,
				];

				continue;
			}

			// Granted-at is the OLDEST surviving token; valid-until the newest.
			$clients[ $client_id ]['created_at'] = min( $clients[ $client_id ]['created_at'], $created );
			$clients[ $client_id ]['expires_at'] = max( $clients[ $client_id ]['expires_at'], $expires );
		}

		$rows = [];

		foreach ( $clients as $client_id => $data ) {
			$client = self::get_client( $client_id );
			$user   = $data['user_id'] ? get_userdata( $data['user_id'] ) : null;

			$rows[] = [
				'id'           => self::PUBLIC_ID_PREFIX . $client_id,
				// Distinguishes the two systems in the UI: a delegated grant has
				// no secret an administrator could ever have copied.
				'source'       => 'oauth',
				'name'         => ( $client && '' !== $client['client_name'] )
					? $client['client_name']
					: __( 'Unnamed application', 'templately' ),
				'user_id'      => $data['user_id'],
				'user_login'   => $user ? $user->user_login : '',
				// Never '' — the settings table feeds this straight into a
				// controlled <select> whose options are read|full, and an empty
				// value renders a blank row with no selection.
				'access_level' => ToolDescriptor::ACCESS_FULL === $data['access_level']
					? ToolDescriptor::ACCESS_FULL
					: ToolDescriptor::ACCESS_READ,
				'created_at'   => $data['created_at'],
				// Not tracked per-request for delegated tokens; the UI shows a dash.
				'last_used_at' => null,
				'expires_at'   => $data['expires_at'],
			];
		}

		// A plain closure, not `fn()` — this tree must parse on the advertised
		// PHP 7.2 floor. Nothing from the enclosing scope is read, so there is
		// no `use` clause; `static` keeps `$this` unbound as before.
		usort( $rows, static function( $a, $b ) {
			return $b['created_at'] <=> $a['created_at'];
		} );

		return $rows;
	}

	/**
	 * @param string $public_id
	 * @return string '' when the id does not belong to this store.
	 */
	public static function client_id_from_public_id( string $public_id ): string {
		return 0 === strpos( $public_id, self::PUBLIC_ID_PREFIX )
			? substr( $public_id, strlen( self::PUBLIC_ID_PREFIX ) )
			: '';
	}

	/**
	 * Change what an already-connected application may do, WITHOUT making the
	 * user disconnect and re-approve. Applies to the tokens it holds now and to
	 * anything it refreshes into, since a refresh copies the level forward.
	 *
	 * @param string $client_id
	 * @param string $level
	 * @return bool
	 */
	public static function set_client_access_level( string $client_id, string $level ): bool {
		// Normalised HERE and not left to the caller: this widens what an agent
		// may do, so it must not depend on every future call site remembering.
		// Toward READ, matching the consent screen's least-privilege default.
		$level = ToolDescriptor::ACCESS_FULL === $level
			? ToolDescriptor::ACCESS_FULL
			: ToolDescriptor::ACCESS_READ;

		$found = false;

		foreach ( self::option_names( self::TOKEN_PREFIX ) as $key ) {

			$record = get_option( $key, null );

			if ( ! is_array( $record ) || (string) ( $record['client_id'] ?? '' ) !== $client_id ) {
				continue;
			}

			// Skip rows already past their expiry, consistent with
			// list_connections() — no point rewriting a dead credential.
			$expires = (int) ( $record['expires_at'] ?? 0 );

			if ( $expires > 0 && $expires < time() ) {
				continue;
			}

			$record['access_level'] = $level;

			update_option( $key, $record, 'no' );

			$found = true;
		}

		return $found;
	}

	/**
	 * Revoke every record belonging to one application — its tokens, any grant
	 * still in flight, and the registration itself, so a stale authorize link
	 * cannot resurrect it.
	 *
	 * @param string $client_id
	 * @return bool
	 */
	public static function revoke_client( string $client_id ): bool {
		$index   = self::index();
		$removed = [];

		// From the DATABASE, not the index — a revoke that misses a live row is
		// the whole failure mode this guards against.
		$keys = array_merge(
			self::option_names( self::TOKEN_PREFIX ),
			self::option_names( self::GRANT_PREFIX )
		);

		foreach ( $keys as $key ) {
			$record = get_option( $key, null );

			if ( ! is_array( $record ) || (string) ( $record['client_id'] ?? '' ) !== $client_id ) {
				continue;
			}

			delete_option( $key );

			$removed[] = $key;
		}

		$client_key = self::CLIENT_PREFIX . $client_id;

		if ( null !== get_option( $client_key, null ) ) {
			delete_option( $client_key );

			$removed[] = $client_key;
		}

		if ( empty( $removed ) ) {
			return false;
		}

		// ONE index write for the whole revocation. Calling deindex() per key
		// would re-read and re-write the index for every row.
		foreach ( $removed as $key ) {
			unset( $index[ $key ] );
		}

		update_option( self::INDEX_OPTION, $index, 'no' );

		return true;
	}

	// ------------------------------------------------------------ lifecycle

	/**
	 * Whether this store holds anything that can actually AUTHENTICATE.
	 *
	 * Counts tokens and in-flight grants only — NOT client registrations. A bare
	 * registration confers nothing, and `/mcp/oauth/register` is deliberately
	 * public, so counting the whole index let any unauthenticated caller flip
	 * `Credentials::site_has_any()` to true for good: that is the FR-023 gate
	 * that keeps the endpoint inert until an administrator connects, the gate on
	 * serving discovery, and the `connected` flag in the settings UI. It also
	 * made revoking the last connection leave the page claiming "connected" over
	 * an empty list, because a registration row (indexed with no expiry) is
	 * never reclaimed by the sweep.
	 *
	 * @return bool
	 */
	public static function has_any(): bool {
		return ! empty( self::option_names( self::TOKEN_PREFIX ) )
			|| ! empty( self::option_names( self::GRANT_PREFIX ) );
	}

	/**
	 * Kill switch — clears every delegated record (FR-024a).
	 */
	public static function purge_all(): void {
		// Enumerated from the database. Walking the index would silently spare
		// any row whose index entry had been lost to a concurrent write — and a
		// kill switch that leaves a working credential behind is worse than no
		// kill switch, because it reports success.
		$keys = array_merge(
			self::option_names( self::CLIENT_PREFIX ),
			self::option_names( self::GRANT_PREFIX ),
			self::option_names( self::TOKEN_PREFIX )
		);

		foreach ( $keys as $key ) {
			delete_option( $key );
		}

		delete_option( self::INDEX_OPTION );
	}

	/**
	 * Reclaim rows nothing ever read back. Expiry is also enforced on read, so
	 * this only matters for records abandoned mid-flow (FR-030).
	 */
	public static function sweep(): void {
		$index   = self::index();
		$changed = false;

		foreach ( $index as $key => $expires ) {
			if ( $expires > 0 && $expires < time() ) {
				delete_option( $key );
				unset( $index[ $key ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( self::INDEX_OPTION, $index, 'no' );
		}
	}

	/**
	 * How many records are past their expiry right now.
	 *
	 * Read-only; used by the shared cleanup service to estimate before it runs.
	 */
	public static function count_expired(): int {
		$expired = 0;

		foreach ( self::index() as $expires ) {
			if ( $expires > 0 && $expires < time() ) {
				$expired++;
			}
		}

		return $expired;
	}

	/**
	 * Bind the sweep to its legacy hook.
	 *
	 * NO LONGER SCHEDULES ANYTHING. This module used to register its own daily
	 * WP-Cron event; scheduling moved to `modules/utilities/` (spec 052), which
	 * runs the plugin's ONE cleanup sweep and unschedules this event where a site
	 * already stored it. The hook binding is kept so a stored event that fires
	 * before that unscheduling still does the right thing rather than nothing.
	 */
	public static function schedule_sweep(): void {
		add_action( self::SWEEP_HOOK, [ self::class, 'sweep' ] );
	}

	// -------------------------------------------------------------- internals

	/**
	 * @param string $key
	 * @param array  $record
	 * @param int    $ttl
	 */
	private static function put( string $key, array $record, int $ttl = 0 ): void {
		update_option( $key, $record, 'no' );

		$expires = $record['expires_at'] ?? ( $ttl > 0 ? time() + $ttl : 0 );

		$index         = self::index();
		$index[ $key ] = (int) $expires;

		update_option( self::INDEX_OPTION, $index, 'no' );
	}

	/**
	 * Expiry enforced on read: a row past its time is treated as absent AND
	 * deleted, so a stale record can never authenticate even if the sweep has
	 * not run.
	 *
	 * @param string $key
	 * @param int    $max_age Fallback when the record carries no expires_at.
	 * @return array|null
	 */
	private static function get( string $key, int $max_age ) {
		$record = get_option( $key, null );

		if ( ! is_array( $record ) ) {
			return null;
		}

		$expires = (int) ( $record['expires_at'] ?? ( (int) ( $record['created_at'] ?? 0 ) + $max_age ) );

		if ( $expires > 0 && $expires < time() ) {
			delete_option( $key );
			self::deindex( $key );

			return null;
		}

		return $record;
	}

	/**
	 * @return array
	 */
	private static function index(): array {
		$index = get_option( self::INDEX_OPTION, [] );

		return is_array( $index ) ? $index : [];
	}

	/**
	 * Every option name this store owns, read from the DATABASE rather than the
	 * index.
	 *
	 * The index is a convenience cache, not a source of truth, and treating it
	 * as one was a real hole: authentication resolves a token by reading its
	 * option key DIRECTLY (`find_access_token`), while revocation and the sweep
	 * walked only the index. Those writes are unlocked read-modify-write cycles
	 * on one shared option, so a rotation landing concurrently with a sweep or a
	 * revoke could lose its index entries — leaving a token row that still
	 * authenticates for its full lifetime but is invisible to "Revoke", which
	 * would report success over a connection that kept working.
	 *
	 * Anything security-relevant — revoking, counting credentials — must ask the
	 * database. The index survives only to make the sweep cheap.
	 *
	 * @param string $prefix
	 * @return string[]
	 */
	private static function option_names( string $prefix ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array_values(
				array_filter(
					array_keys( self::index() ),
					static function ( $key ) use ( $prefix ) {
						return 0 === strpos( $key, $prefix );
					}
				)
			);
		}

		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb.
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		return is_array( $names ) ? $names : [];
	}

	private static function deindex( string $key ): void {
		$index = self::index();

		if ( isset( $index[ $key ] ) ) {
			unset( $index[ $key ] );
			update_option( self::INDEX_OPTION, $index, 'no' );
		}
	}
}
