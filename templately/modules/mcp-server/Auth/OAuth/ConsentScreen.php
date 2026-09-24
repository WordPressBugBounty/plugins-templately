<?php
/**
 * The approval screen an administrator sees when a URL-only client asks to
 * connect (spec 044, FR-031–FR-035, FR-038).
 *
 * ## Why this is a front-end page and not a REST route
 *
 * A browser returning from wp-login.php carries an authentication cookie but no
 * REST nonce. Inside REST, core therefore does not treat the visitor as logged
 * in — so the screen would bounce them to log in again, and again, forever.
 * Serving it as an ordinary front-end page on `parse_request` is what satisfies
 * FR-032. The reference implementation documents hitting exactly this.
 *
 * @package Templately\Modules\McpServer\Auth\OAuth
 */

namespace Templately\Modules\McpServer\Auth\OAuth;

use Templately\Modules\McpCore\Registry\ToolDescriptor;

class ConsentScreen {

	const NONCE_ACTION = 'templately_mcp_consent';

	/**
	 * @param string $client_id
	 * @return string
	 */
	private static function nonce_action( string $client_id ): string {
		return self::NONCE_ACTION . '_' . $client_id;
	}

	public static function render(): void {
		$params = self::request_params();

		$client = RecordStore::get_client( (string) ( $params['client_id'] ?? '' ) );

		if ( null === $client ) {
			self::render_error( __( 'Unknown client. Ask the application to register again.', 'templately' ) );
		}

		$redirect_uri = (string) ( $params['redirect_uri'] ?? '' );

		// Exact match against a PRE-REGISTERED destination. On mismatch we
		// redirect NOWHERE — bouncing to an unverified destination is precisely
		// the open redirect this guards against (FR-034).
		if ( ! self::redirect_uri_registered( $redirect_uri, (array) $client['redirect_uris'] ) ) {
			self::render_error( __( 'This application asked to return to an address it did not register.', 'templately' ) );
		}

		$challenge = (string) ( $params['code_challenge'] ?? '' );
		$method    = (string) ( $params['code_challenge_method'] ?? '' );

		// Authentication comes FIRST, before any branch that can redirect off-site.
		//
		// Returning a protocol error to the client's registered address is
		// correct per RFC 6749 — but registration here is public and
		// unauthenticated, so doing it before the login check turned this page
		// into an open redirect anyone could aim anywhere: register a client
		// with redirect_uri https://evil.example/x, hand out a link to
		// /templately/authorize?...&code_challenge_method=x on the victim's own
		// domain, and every visitor gets 302'd to the attacker. Now an anonymous
		// visitor only ever sees a rendered error on this site.
		if ( ! is_user_logged_in() ) {
			// Return here afterwards — no loop, because this is not a REST route.
			wp_safe_redirect( wp_login_url( self::current_url() ) );
			exit;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			self::render_error( __( 'Only administrators can approve an agent connection for this site.', 'templately' ) );
		}

		if ( 'S256' !== $method || '' === $challenge ) {
			self::redirect_error( $redirect_uri, 'invalid_request', (string) ( $params['state'] ?? '' ) );
		}

		// RFC 8707 / MCP: the client names the server it intends to use the
		// token with. If that is not us, say so here rather than minting a
		// credential the client will believe is scoped to someone else.
		if ( ! RecordStore::resource_matches( (string) ( $params['resource'] ?? '' ) ) ) {
			self::redirect_error( $redirect_uri, 'invalid_target', (string) ( $params['state'] ?? '' ) );
		}

		// Absent an explicit request, grant READ (FR-038). The reference
		// implementation defaults the other way and silently grants write.
		$requested = self::requested_level( (string) ( $params['scope'] ?? '' ) );

		if ( 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) )
			&& isset( $_POST['templately_mcp_consent_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['templately_mcp_consent_nonce'] ) ), self::nonce_action( (string) $client['client_id'] ) ) ) {

			// Explicit refusal. Without this the only way to decline was closing
			// the tab, which leaves the client waiting on a request that will
			// never resolve; the protocol expects `access_denied` instead.
			$decision = isset( $_POST['templately_mcp_decision'] )
				? sanitize_text_field( wp_unslash( $_POST['templately_mcp_decision'] ) )
				: 'approve';

			if ( 'approve' !== $decision ) {
				self::redirect_error( $redirect_uri, 'access_denied', (string) ( $params['state'] ?? '' ) );
			}

			// The approver decides; the client only PROPOSES. Without this the
			// only way to get full access was for the client to ask for it in
			// exactly the right way, leaving an administrator who wanted to
			// grant more (or less) with no control at all.
			$granted = isset( $_POST['templately_mcp_access'] )
				? self::normalize_level( sanitize_text_field( wp_unslash( $_POST['templately_mcp_access'] ) ) )
				: $requested;

			$code = RecordStore::create_grant(
				[
					'client_id'    => $client['client_id'],
					'user_id'      => get_current_user_id(),
					'access_level' => $granted,
					'redirect_uri' => $redirect_uri,
					'challenge'    => $challenge,
					// The MCP endpoint's canonical URI (audience), not the
					// issuer — see RecordStore::audience().
					'resource'     => RecordStore::audience(),
					'expires_at'   => time() + RecordStore::GRANT_TTL,
				]
			);

			// Encoded for the same reason as current_url(): a `state` on the
			// standard base64 alphabet contains `+`, which travels raw through
			// add_query_arg and reaches the client decoded as a space — read as
			// a CSRF mismatch, so the callback is rejected.
			$target = add_query_arg(
				array_map(
					'rawurlencode',
					array_filter(
						[
							'code'  => $code,
							'state' => (string) ( $params['state'] ?? '' ),
							// RFC 9207. Lets a client that talks to more than one
							// authorization server confirm WHICH one answered, which
							// is the defence against mix-up attacks. A MUST for
							// clients in the MCP 2026-07-28 revision, and the server
							// has to emit it for them to check it.
							'iss'   => RecordStore::issuer(),
						]
					)
				),
				$redirect_uri
			);

			// Not wp_safe_redirect: the destination is external by design, but it
			// was verified above as an exact pre-registered match.
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- see above; wp_safe_redirect() would strip every valid OAuth client callback.
			wp_redirect( $target );
			exit;
		}

		self::render_form( $client, $requested, $params );
	}

	/**
	 * Exact string match — EXCEPT the port for a loopback address.
	 *
	 * RFC 8252 §7.3: "The authorization server MUST allow any port to be
	 * specified at the time of the request for loopback IP redirect URIs, to
	 * accommodate clients that obtain an available ephemeral port from the
	 * operating system at the time of the request." RFC 9700 §2.1 restates the
	 * carve-out: exact matching "except for port numbers in localhost
	 * redirection URIs of native apps".
	 *
	 * Matching the port too meant a desktop agent whose OS handed it a different
	 * ephemeral port than the one it registered with was refused outright. It
	 * worked here only because the clients tested happen to re-register on every
	 * login attempt; one that registers once and reconnects later would not.
	 *
	 * The relaxation is scoped to loopback: any local port is by definition
	 * already the approver's own machine, so it grants no reach an attacker did
	 * not already have. It is NOT applied to https, where the port is part of
	 * the origin.
	 *
	 * @param string $presented
	 * @param array  $registered
	 * @return bool
	 */
	private static function redirect_uri_registered( string $presented, array $registered ): bool {
		if ( in_array( $presented, $registered, true ) ) {
			return true;
		}

		$parts = wp_parse_url( $presented );
		$host  = strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) );

		if ( 'http' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| ! in_array( $host, [ '127.0.0.1', '::1', 'localhost' ], true ) ) {
			return false;
		}

		$without_port = static function ( $uri ) {
			$p = wp_parse_url( $uri );

			if ( empty( $p['host'] ) ) {
				return null;
			}

			return strtolower( (string) ( $p['scheme'] ?? '' ) ) . '://'
				. strtolower( trim( (string) $p['host'], '[]' ) )
				. ( $p['path'] ?? '' )
				. ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		};

		$target = $without_port( $presented );

		if ( null === $target ) {
			return false;
		}

		foreach ( $registered as $candidate ) {
			if ( $target === $without_port( (string) $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The access level a client's `scope` parameter asks for.
	 *
	 * `scope` is a SPACE-DELIMITED LIST (RFC 6749 §3.3), not a single value.
	 * This previously compared the whole string against `full`, so a client
	 * asking for everything it could — ChatGPT sends `scope=read full`, which is
	 * both correct and exactly what this server's own discovery document
	 * advertises in `scopes_supported` — matched neither branch and silently
	 * fell through to read-only, with no way for the approver to override it.
	 *
	 * Unrecognised or absent scopes still resolve to READ: least privilege is
	 * the safe default for a value the CLIENT controls (FR-038). Note this is
	 * the opposite default to `Credentials::normalize_level()`, which fails
	 * closed toward full for a level an ADMINISTRATOR typed into the settings
	 * UI — different source of truth, different safe direction.
	 *
	 * @param string $scope
	 * @return string
	 */
	private static function requested_level( string $scope ): string {
		$scopes = preg_split( '/\s+/', trim( $scope ), -1, PREG_SPLIT_NO_EMPTY );

		return in_array( ToolDescriptor::ACCESS_FULL, (array) $scopes, true )
			? ToolDescriptor::ACCESS_FULL
			: ToolDescriptor::ACCESS_READ;
	}

	/**
	 * @param string $level
	 * @return string
	 */
	private static function normalize_level( string $level ): string {
		return ToolDescriptor::ACCESS_FULL === $level
			? ToolDescriptor::ACCESS_FULL
			: ToolDescriptor::ACCESS_READ;
	}

	/**
	 * @return array
	 */
	private static function request_params(): array {
		$source = ( 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) ) ? $_POST : $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- read-only parameter collection; the state-changing branch verifies a nonce before acting.
		$out    = [];

		foreach ( [ 'client_id', 'redirect_uri', 'state', 'scope', 'code_challenge', 'code_challenge_method', 'resource' ] as $key ) {
			if ( isset( $source[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( wp_unslash( $source[ $key ] ) );
			}
		}

		return $out;
	}

	/**
	 * @param array  $client
	 * @param string $requested
	 * @param array  $params
	 * @return void
	 */
	private static function render_form( array $client, string $requested, array $params ): void {
		$user = wp_get_current_user();
		$full = ToolDescriptor::ACCESS_FULL === $requested;

		self::head( __( 'Approve agent connection', 'templately' ) );

		self::brand();

		echo '<h1>' . esc_html__( 'Approve agent connection', 'templately' ) . '</h1>';

		printf(
			'<p class="tmpl-lede">%s</p>',
			esc_html__( 'An application is asking to connect to this site as an AI agent.', 'templately' )
		);

		printf(
			'<div class="tmpl-row"><span class="tmpl-key">%s</span>'
			. '<span class="tmpl-val" data-tlid="mcp-consent-client">%s</span></div>',
			esc_html__( 'Application', 'templately' ),
			// Self-reported by the client — untrusted, so escaped.
			esc_html( '' !== $client['client_name'] ? $client['client_name'] : __( 'Unnamed application', 'templately' ) )
		);

		// The destination, shown plainly. MCP requires the authorization screen to
		// display the redirect URI hostname: the application NAME above is
		// self-reported and unverifiable, so where the credential is about to be
		// sent is the only fact on this screen an approver can actually check.
		$destination = (string) ( $params['redirect_uri'] ?? '' );
		$host        = (string) wp_parse_url( $destination, PHP_URL_HOST );
		$loopback    = in_array( strtolower( trim( $host, '[]' ) ), [ '127.0.0.1', '::1', 'localhost' ], true );

		printf(
			'<div class="tmpl-row"><span class="tmpl-key">%1$s</span>'
			. '<span class="tmpl-val" data-tlid="mcp-consent-destination">%2$s</span></div>',
			esc_html__( 'Returns to', 'templately' ),
			esc_html( '' !== $host ? $host : $destination )
		);

		// Loopback means "an application running on the approver's own computer".
		// That is normal for a desktop agent and wrong for anything else, and
		// nothing about the address itself proves which — so say so.
		if ( $loopback ) {
			printf(
				'<p class="tmpl-lede" style="margin:8px 0 0;font-size:12px">%s</p>',
				esc_html__( 'This address is on your own computer — expected for a desktop or command-line agent.', 'templately' )
			);
		}

		// Opened HERE, not just around the buttons: the access control below is a
		// form field, so everything from this point must sit inside the form.
		echo '<form method="post" data-tlid="mcp-consent-form">';

		// A CONTROL, not a label. The client's `scope` only sets the default —
		// the person approving is the resource owner and decides what is
		// actually granted, without having to reconnect or hand-edit a URL.
		printf(
			'<div class="tmpl-row"><span class="tmpl-key">%1$s</span><span class="tmpl-val">'
			. '<select name="templately_mcp_access" id="templately-mcp-access" class="tmpl-select" data-tlid="mcp-consent-access">'
			. '<option value="%2$s"%4$s>%6$s</option>'
			. '<option value="%3$s"%5$s>%7$s</option>'
			. '</select></span></div>',
			esc_html__( 'Access', 'templately' ),
			esc_attr( ToolDescriptor::ACCESS_READ ),
			esc_attr( ToolDescriptor::ACCESS_FULL ),
			$full ? '' : ' selected',
			$full ? ' selected' : '',
			esc_html__( 'Read-only', 'templately' ),
			esc_html__( 'Full access', 'templately' )
		);

		printf(
			'<div class="tmpl-row"><span class="tmpl-key">%s</span><span class="tmpl-val">%s</span></div>',
			esc_html__( 'Acting as', 'templately' ),
			esc_html( $user->user_login )
		);

		printf(
			'<div class="tmpl-row"><span class="tmpl-key">%s</span><span class="tmpl-val">%s</span></div>',
			esc_html__( 'Site', 'templately' ),
			esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) )
		);

		// Both consequences are rendered; the one matching the current selection
		// is shown. With scripting off the initially-correct one is already
		// visible, so the screen never misdescribes what it is about to grant.
		printf(
			'<p class="tmpl-lede tmpl-consequence" data-level="%1$s" style="margin:16px 0 0;font-size:13px%2$s">%3$s</p>'
			. '<p class="tmpl-lede tmpl-consequence" data-level="%4$s" style="margin:16px 0 0;font-size:13px%5$s">%6$s</p>',
			esc_attr( ToolDescriptor::ACCESS_READ ),
			$full ? ';display:none' : '',
			esc_html__( 'This application will be able to browse Templately designs only. It cannot change anything on this site.', 'templately' ),
			esc_attr( ToolDescriptor::ACCESS_FULL ),
			$full ? '' : ';display:none',
			esc_html__( 'This application will be able to browse Templately designs and import them — creating pages, templates and media on this site.', 'templately' )
		);

		// Confused-deputy guard: the site cannot tell a genuine request from one a
		// third party talked the user into opening, so it has to say so plainly.
		printf(
			'<div class="tmpl-note" data-tlid="mcp-consent-warning"><span>&#9888;</span><span>%s</span></div>',
			esc_html__( 'Only approve if you just asked this application to connect. If you did not start this, close this page.', 'templately' )
		);

		// Bound to THIS client, so a nonce minted while approving one
		// application cannot be replayed to approve a different one.
		wp_nonce_field( self::nonce_action( $client['client_id'] ), 'templately_mcp_consent_nonce' );

		// `resource` included: it is validated on POST as well as GET now, so
		// losing it here would turn an approve into a resource mismatch.
		foreach ( [ 'client_id', 'redirect_uri', 'state', 'scope', 'code_challenge', 'code_challenge_method', 'resource' ] as $key ) {
			printf(
				'<input type="hidden" name="%1$s" value="%2$s" />',
				esc_attr( $key ),
				esc_attr( (string) ( $params[ $key ] ?? '' ) )
			);
		}

		printf(
			'<div class="tmpl-actions">'
			. '<button type="submit" name="templately_mcp_decision" value="deny" class="tmpl-btn tmpl-btn-ghost" data-tlid="mcp-consent-deny">%1$s</button>'
			. '<button type="submit" name="templately_mcp_decision" value="approve" class="tmpl-btn tmpl-btn-primary" data-tlid="mcp-consent-approve">%2$s</button>'
			. '</div>',
			esc_html__( 'Cancel', 'templately' ),
			esc_html__( 'Approve', 'templately' )
		);

		echo '</form>';

		// Keeps the consequence copy honest when the selection changes. Inline
		// and dependency-free so it works on a site with no scripts enqueued.
		echo '<script>(function(){var s=document.getElementById("templately-mcp-access");if(!s)return;'
			. 'function sync(){var v=s.value;Array.prototype.forEach.call('
			. 'document.querySelectorAll(".tmpl-consequence"),function(p){'
			. 'p.style.display=(p.getAttribute("data-level")===v)?"":"none";});}'
			. 's.addEventListener("change",sync);sync();})();</script>';

		self::foot( __( 'Templately · You can revoke this connection at any time from Templately → Settings → AI Agents.', 'templately' ) );
	}

	/**
	 * @param string $message
	 * @return void
	 */
	private static function render_error( string $message ): void {
		status_header( 400 );
		self::head( __( 'Connection request refused', 'templately' ) );

		self::brand();

		echo '<h1>' . esc_html__( 'Connection request refused', 'templately' ) . '</h1>';

		printf(
			'<div class="tmpl-error" data-tlid="mcp-consent-error"><span>&#9888;</span><span>%s</span></div>',
			esc_html( $message )
		);

		printf(
			'<p class="tmpl-lede" style="margin-top:16px;font-size:13px">%s</p>',
			esc_html__( 'Nothing was granted and no connection was created. You can close this page.', 'templately' )
		);

		self::foot();
	}

	/**
	 * @param string $redirect_uri
	 * @param string $error
	 * @param string $state
	 * @return void
	 */
	private static function redirect_error( string $redirect_uri, string $error, string $state ): void {
		// Not wp_safe_redirect: RFC 6749 §4.1.2.1 requires the error to be delivered
		// to the client's own redirect_uri, which is external by design and was
		// verified against the registration before this is reached.
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- see above.
		wp_redirect(
			add_query_arg(
				array_map( 'rawurlencode', array_filter( [ 'error' => $error, 'state' => $state ] ) ),
				$redirect_uri
			)
		);
		exit;
	}

	/**
	 * Where to send the visitor back to once they have logged in.
	 *
	 * Rebuilt from the PARSED parameters — never from `$_SERVER['REQUEST_URI']`.
	 * `sanitize_text_field()` deletes every `%XX` sequence it finds, and
	 * REQUEST_URI is still percent-encoded at this point, so filtering it there
	 * silently destroys the exact characters a redirect_uri is made of:
	 * `http://127.0.0.1:53895/callback/abc` came back as
	 * `http127.0.0.153895callbackabc`, which then failed the exact-match check
	 * against the registered value with "This application asked to return to an
	 * address it did not register."
	 *
	 * It only ever fired for a visitor who was NOT already logged in — i.e. the
	 * ordinary first connection, and never in local testing from an open
	 * wp-admin session. Guarded by `tests/e2e/specs/046-mcp-oauth.spec.js`,
	 * which deliberately starts from a cold context so the login bounce runs.
	 *
	 * The parsed values are safe to re-encode: they were sanitized AFTER PHP
	 * decoded them, so no percent-encoding remained to be eaten.
	 *
	 * @return string
	 */
	private static function current_url(): string {
		// rawurlencode is NOT optional here. add_query_arg() urlencodes only the
		// args ALREADY present in the base URL; the ones passed to it are
		// serialised by build_query() with $urlencode = false, i.e. emitted raw.
		// So an unencoded `&` in a redirect_uri truncates it on the way back
		// (`…/cb?a=1&b=2` returns as `…/cb?a=1`) and a `+` in a base64 `state`
		// decodes to a space — reintroducing the same "address it did not
		// register" failure this method exists to prevent, just by a different
		// route. Verified against add_query_arg directly, not assumed.
		$params = array_map( 'rawurlencode', self::request_params() );

		// Preserve the non-pretty entry point for sites without rewrites, where
		// the screen is reached as `/?templately_mcp_authorize=1&…`.
		if ( ! empty( $_GET['templately_mcp_authorize'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only routing check; the state-changing branch verifies a nonce.
			$params['templately_mcp_authorize'] = '1';

			return add_query_arg( $params, home_url( '/' ) );
		}

		return add_query_arg( $params, home_url( '/templately/authorize' ) );
	}

	/**
	 * Styles are INLINE and self-contained on purpose.
	 *
	 * This is a front-end page, not wp-admin, so the admin bundle (and Tailwind
	 * with it) is not loaded here — and should not be, for one page. More
	 * importantly this screen must render correctly whatever theme is active,
	 * including a broken one: it is where a user grants an AI write access to
	 * their site, and an unstyled or theme-mangled consent screen is one a
	 * careful user is right to distrust.
	 *
	 * Values mirror the Templately tokens used by the settings UI
	 * (#5453fd primary, #1d2939 title, #667085 muted, #eaecf0 border) so the two
	 * surfaces read as the same product.
	 *
	 * @param string $title
	 * @return void
	 */
	private static function head( string $title ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		// Must be set HERE. Core's send_frame_options_header() is hooked on
		// admin_init/login_init, and this is deliberately a front-end page, so
		// nothing else protects it. Framed, an attacker who registered their own
		// client (registration is public by design) could overlay this screen and
		// UI-redress the Approve button into granting themselves full access —
		// and the confused-deputy warning below would never be seen.
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );

		$css = '
			*{box-sizing:border-box;margin:0;padding:0}
			body{font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
				background:#f4f4f5;color:#1d2939;line-height:1.6;
				display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
			.tmpl-card{width:100%;max-width:460px;background:#fff;border:1px solid #eaecf0;
				border-radius:12px;padding:32px;box-shadow:0 1px 3px rgba(16,24,40,.06)}
			.tmpl-brand{margin-bottom:22px;line-height:0}
			.tmpl-brand svg{width:132px;height:auto;display:block}
			.tmpl-brand-text{font-size:12px;font-weight:600;letter-spacing:.08em;
				text-transform:uppercase;color:#5453fd;line-height:1.6}
			h1{font-size:20px;font-weight:600;color:#1d2939;margin-bottom:8px;line-height:1.3}
			.tmpl-lede{font-size:14px;color:#667085;margin-bottom:24px}
			.tmpl-row{display:flex;justify-content:space-between;align-items:center;gap:16px;
				padding:12px 0;border-top:1px solid #f2f4f7;font-size:14px}
			.tmpl-row:last-of-type{border-bottom:1px solid #f2f4f7}
			.tmpl-key{color:#667085}
			.tmpl-val{color:#1d2939;font-weight:500;text-align:right;word-break:break-word}
			.tmpl-badge{display:inline-block;border-radius:999px;padding:3px 10px;
				font-size:12px;font-weight:500}
			.tmpl-badge-read{background:#f2f4f7;color:#475467}
			.tmpl-badge-full{background:#fef0c7;color:#b54708}
			.tmpl-select{font:inherit;font-size:13px;font-weight:500;color:#1d2939;background:#fff;
				border:1px solid #d0d5dd;border-radius:8px;padding:6px 10px;min-width:140px;
				box-shadow:0 1px 2px rgba(16,24,40,.05);cursor:pointer}
			.tmpl-select:focus{outline:none;border-color:#5453fd;box-shadow:0 0 0 3px rgba(84,83,253,.16)}
			.tmpl-note{display:flex;gap:8px;margin-top:20px;padding:12px 14px;border-radius:8px;
				background:#fffcf5;border:1px solid #fedf89;color:#93370d;font-size:12.5px;line-height:1.55}
			.tmpl-actions{display:flex;gap:10px;margin-top:24px}
			.tmpl-btn{flex:1;display:inline-block;text-align:center;border-radius:8px;padding:11px 16px;
				font-size:14px;font-weight:500;cursor:pointer;border:1px solid transparent;
				font-family:inherit;text-decoration:none;transition:background .15s}
			.tmpl-btn-primary{background:#5453fd;border-color:#5453fd;color:#fff}
			.tmpl-btn-primary:hover{background:#4341e0}
			.tmpl-btn-ghost{background:#fff;border-color:#d0d5dd;color:#344054}
			.tmpl-btn-ghost:hover{background:#f9fafb}
			.tmpl-error{display:flex;gap:10px;padding:14px 16px;border-radius:8px;
				background:#fffbfa;border:1px solid #fda29b;color:#b42318;font-size:14px;line-height:1.55}
			.tmpl-foot{margin-top:20px;font-size:12px;color:#98a2b3;text-align:center}
		';

		printf(
			'<!DOCTYPE html><html %1$s><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow">'
			. '<title>%2$s</title><style>%3$s</style></head><body><div class="tmpl-card">',
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP core builds this attribute string itself; there is no escaping function for it.
			get_language_attributes(),
			esc_html( $title ),
			$css // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static stylesheet, no dynamic input.
		);
	}

	/**
	 * The Templately lockup, inlined.
	 *
	 * Read from the shipped asset rather than pasted into PHP so a brand update
	 * propagates on its own, and INLINED rather than linked because this page
	 * must render standalone — an <img> that 404s on a misconfigured site would
	 * leave the consent screen looking broken, which is the one impression it
	 * cannot afford. Falls back to the wordmark as text if the file is missing.
	 *
	 * @return void
	 */
	private static function brand(): void {
		$svg = defined( 'TEMPLATELY_PATH' )
			? TEMPLATELY_PATH . 'assets/images/logos/logo-full.svg'
			: '';

		if ( '' !== $svg && is_readable( $svg ) ) {
			$markup = file_get_contents( $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin asset, not a remote fetch.

			if ( is_string( $markup ) && 0 === strpos( ltrim( $markup ), '<svg' ) ) {
				printf(
					'<div class="tmpl-brand" role="img" aria-label="%s">%s</div>',
					esc_attr__( 'Templately', 'templately' ),
					$markup // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- first-party asset shipped with the plugin.
				);

				return;
			}
		}

		printf( '<div class="tmpl-brand tmpl-brand-text">%s</div>', esc_html__( 'Templately', 'templately' ) );
	}

	/**
	 * @param string $note Footer line. Empty on screens where the default
	 *                     "you can revoke this later" would be untrue — nothing
	 *                     is created on the error path.
	 * @return void
	 */
	private static function foot( string $note = '' ): void {
		if ( '' !== $note ) {
			printf( '<div class="tmpl-foot">%s</div>', esc_html( $note ) );
		}

		echo '</div></body></html>';

		exit;
	}
}
