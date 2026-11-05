<?php

namespace Templately\Modules\AiEditor\REST;

use Templately\API\API;
use Templately\Utils\Database;
use Templately\Utils\Helper;
use Templately\Utils\Options;
use Templately\Utils\Response\FatalGuard;
use WP_REST_Request;
use WP_REST_Response;

/**
 * AI Editor chat proxy (041) — templately/v1/ai-editor/*.
 *
 * The plugin never calls an AI provider (FR-020): every conversation turn is
 * proxied to the Templately app (v2/ai-editor/chat, Bearer via Helper). This
 * controller owns permissions, sanitization of the turn payload, the
 * developer-mode mock (research R11), and the pending-turn poll route
 * (analyze P1). Change application happens client-side through the editor's
 * native APIs — never here.
 */
class AIEditor extends API {

	const CLOUD_ENDPOINT = 'v2/ai-editor/chat';

	const APPLY_REPORT_ENDPOINT = 'v2/ai-editor/apply-report';

	/** Cloud route for the one-time free-credit offer (app PRD ai-editor-free-credits). */
	const FREE_CREDITS_ENDPOINT = 'v2/ai-editor/free-credits';

	/** Per-WP-user flag: the library-page offer alert was closed and never returns. */
	const OFFER_DISMISSED_KEY = 'ai_editor_offer_dismissed';

	/** Developer mock only: per-user "already claimed" marker so a second claim refuses. */
	const MOCK_CLAIMED_TRANSIENT = 'templately_ai_editor_mock_free_claimed_';

	/**
	 * Cloud contract major version this plugin speaks (contract §cloud).
	 *
	 * 2 (2026-10-06): the app may run edit turns clause by clause and answer with
	 * `clauses: [{n, status, reason?}]` beside the usual fields
	 * (app docs/prd/improvements/jev-implementation.md, D16). Everything else a
	 * contract-1 response carried is unchanged, so a 1 is still accepted.
	 */
	const CONTRACT_VERSION = 2;

	public function register_routes() {
		$this->post( 'ai-editor/chat', [ $this, 'chat' ] );
		$this->get( 'ai-editor/chat/(?P<process_id>[a-zA-Z0-9_\-]+)', [ $this, 'poll_turn' ] );
		// What an apply did, for the app to check its reply (plugin plan task 3).
		$this->post( 'ai-editor/apply-report', [ $this, 'apply_report' ] );
		// Shared AI credit balance (research R10) — same upstream as the
		// logo-generation route, which stays untouched.
		$this->get( 'ai/available-credits', [ $this, 'available_credits' ] );
		// One-time free credits for a first-time AI Editor user (free-credits plugin plan).
		// The app owns eligibility; these two only relay it.
		$this->get( 'ai-editor/free-credits', [ $this, 'free_credits' ] );
		$this->post( 'ai-editor/free-credits/claim', [ $this, 'claim_free_credits' ] );
		// Local only: the library-page alert's close button.
		$this->post( 'ai-editor/free-credits/dismiss', [ $this, 'dismiss_free_credits' ] );
	}

	/**
	 * GET /ai-editor/free-credits — whether this account may claim the offer.
	 *
	 * Ineligible is still a 200 from the app (`eligible: false` + `reason`), so the
	 * body is relayed as-is and the client decides from `data.eligible`.
	 */
	public function free_credits() {
		if ( $this->is_mock_mode() ) {
			return $this->success( $this->mock_free_credit_offer() );
		}

		// 10 s: a cheap read, asked while the editor loads. A hung app must answer
		// as a failure (the panel retries) before PHP's own 30 s limit kills the
		// request and WordPress answers with its HTML error page.
		return $this->relay_free_credit_response( Helper::make_api_get_request( self::FREE_CREDITS_ENDPOINT, [], [], 10 ) );
	}

	/**
	 * POST /ai-editor/free-credits/claim — claim the offer once.
	 *
	 * A refusal is a 409 from the app (`statusText`/`data.reason` say why); it is
	 * relayed with its status and reason, never folded into a generic failure.
	 */
	public function claim_free_credits() {
		if ( $this->is_mock_mode() ) {
			return $this->mock_free_credit_claim();
		}

		return $this->relay_free_credit_response(
			Helper::make_api_post_request( self::FREE_CREDITS_ENDPOINT . '/claim', [], [], 20 )
		);
	}

	/**
	 * POST /ai-editor/free-credits/dismiss — the library-page alert's close (×).
	 *
	 * Stored on the ACTING WordPress user, never through the global-login fallback
	 * (which would write the flag onto the admin who connected the site).
	 */
	public function dismiss_free_credits() {
		Options::get_instance()->set( self::OFFER_DISMISSED_KEY, true, get_current_user_id() );

		return $this->success( [ 'dismissed' => true ] );
	}

	/**
	 * Relay a free-credits cloud response with its status.
	 *
	 * - 2xx: the cloud body, untouched.
	 * - 4xx with a JSON body (409 refusal, 429 throttle, 403 unverified): the
	 *   body's `statusText`, `message` and `data` with the SAME status, shaped as a
	 *   043 failure envelope (`success:false` + `code`) so RestEnvelope leaves it
	 *   alone instead of collapsing it to a status-derived generic error.
	 * - anything else (transport error, 5xx, non-JSON): a generic 502 failure. A
	 *   5xx body can carry a framework stack trace and never reaches the browser.
	 *
	 * @param mixed $response raw wp_remote_* result
	 * @return WP_REST_Response
	 */
	private function relay_free_credit_response( $response ) {
		$unavailable = new WP_REST_Response(
			self::ai_envelope( 'remote_failed', __( 'The Templately AI service is unavailable right now. Please try again in a moment.', 'templately' ) ),
			502
		);

		if ( is_wp_error( $response ) ) {
			Helper::log( 'free-credits transport error: ' . $response->get_error_message(), 'ai-editor-free-credits', 'error' );
			return $unavailable;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $body ) ? $this->success( $body ) : $unavailable;
		}

		if ( $status < 400 || $status >= 500 || ! is_array( $body ) ) {
			Helper::log( 'free-credits upstream ' . $status . ': ' . substr( (string) wp_remote_retrieve_body( $response ), 0, 500 ), 'ai-editor-free-credits', 'error' );
			return $unavailable;
		}

		$data   = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : [];
		$reason = '';
		if ( isset( $body['statusText'] ) && is_string( $body['statusText'] ) ) {
			$reason = sanitize_key( $body['statusText'] );
		} elseif ( isset( $data['reason'] ) && is_string( $data['reason'] ) ) {
			$reason = sanitize_key( $data['reason'] );
		}
		if ( '' === $reason ) {
			$reason = 429 === $status ? 'rate_limited' : 'remote_failed';
		}

		$message = isset( $body['message'] ) && is_string( $body['message'] ) ? trim( wp_strip_all_tags( $body['message'] ) ) : '';
		if ( '' === $message || strlen( $message ) > 300 ) {
			$message = __( 'The free credits could not be claimed right now.', 'templately' );
		}

		return new WP_REST_Response(
			[
				'success'    => false,
				'code'       => $reason,
				'message'    => $message,
				'status'     => 'error',
				'statusText' => $reason,
				'data'       => $data,
			],
			$status
		);
	}

	/**
	 * GET /ai/available-credits — the user's AI credit balance (FR-023).
	 */
	public function available_credits() {
		if ( $this->is_mock_mode() ) {
			return $this->success( [ 'success' => true, 'available_credit' => 150 ] );
		}

		$response = Helper::make_api_get_request( 'v2/ai/available-credits' );

		if ( is_wp_error( $response ) ) {
			return $this->success( self::ai_envelope( 'remote_failed', $response->get_error_message() ) );
		}

		$response = json_decode( wp_remote_retrieve_body( $response ), true );

		// A missing field must NOT read as a zero balance — coercing an unknown
		// upstream response to 0 would wrongly trip the client's pre-turn gate
		// (FR-025) and block every send. Unknown ⇒ error; the client keeps
		// credits null (display empty, no gate) until the balance is real.
		if ( ! is_array( $response ) || ! isset( $response['available_credit'] ) ) {
			return $this->success( self::ai_envelope( 'remote_failed', __( 'Credit balance is unavailable right now.', 'templately' ) ) );
		}

		return $this->success( [
			'success'          => true,
			'available_credit' => (int) $response['available_credit'],
		] );
	}

	/**
	 * Signed-in Templately account AND WordPress edit permission (FR-002,
	 * Clarification 2026-07-06 Q5). The base class already gates on a valid
	 * api_key via parent::permission_check(); we add the per-post capability.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission_check( WP_REST_Request $request ) {
		$permission = parent::permission_check( $request );
		if ( $permission !== true ) {
			return $permission;
		}

		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( $post_id > 0 ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return $this->error( 'forbidden', __( 'You are not allowed to edit this content.', 'templately' ), 'ai-editor/chat', 403 );
			}
			return true;
		}

		// The poll route carries no post_id — require general editing capability.
		return current_user_can( 'edit_posts' )
			? true
			: $this->error( 'forbidden', __( 'You are not allowed to use the AI editor.', 'templately' ), 'ai-editor/chat', 403 );
	}

	/**
	 * POST /ai-editor/chat — one conversation turn.
	 */
	public function chat() {
		// A turn spends most of its life inside one long cloud call, which puts it
		// in reach of PHP's own limits (execution time, memory). Without this, a
		// fatal answers with WordPress's HTML "critical error" page and the chat
		// panel renders that markup as the assistant's reply. Armed, the same
		// fatal comes back as the 043 envelope the client already understands.
		FatalGuard::arm( 'ai-editor/chat' );

		$payload = $this->parse_chat_request();
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// Confirm-first resolution for a pending site-wide proposal (FR-014):
		// applied entirely plugin-side — the held content never round-trips.
		if ( ! empty( $payload['confirm'] ) ) {
			return $this->success( $this->handle_confirmation( $payload['confirm'] ) );
		}

		// @header/@footer resolve to the ENABLED Templately template (FR-008).
		$site_target = null;
		$handle      = $payload['target']['handle'] ?? '';
		if ( in_array( $handle, [ 'header', 'footer' ], true ) ) {
			$site_target = $this->resolve_site_template( $handle );
			if ( ! $site_target ) {
				// No template enabled → say so, point to Theme Builder, spend nothing.
				return $this->success( [
					'success'         => true,
					'type'            => 'message',
					'conversation_id' => $payload['conversation_id'],
					'code'            => 'no_template_enabled',
					'reply'           => sprintf(
						/* translators: %s: "header" or "footer" */
						__( 'No Templately %s is enabled on this site yet. Enable one under Templately → Theme Builder first, then I can edit it.', 'templately' ),
						$handle === 'header' ? __( 'header', 'templately' ) : __( 'footer', 'templately' )
					),
				] );
			}
			$payload['target']['template_id'] = $site_target['id'];
			$payload['target']['settings']    = [
				'platform' => $site_target['platform'],
				'content'  => $site_target['content'],
			];
		}

		if ( $this->is_mock_mode() ) {
			return $this->success( $this->mock_response( $payload, $site_target ) );
		}

		$payload['contract'] = self::CONTRACT_VERSION;

		// Attribute-bank handshake (docs/ai-editor/ai-editor-bank-plugin-instructions.md):
		// stateless per-turn version report — the server answers with
		// schema_coverage, which lets the client omit block_schemas.
		$payload['plugin_versions'] = self::plugin_versions();

		// Gutenberg global settings (docs/ai-editor/ai-editor-plugin-globals.md): the raw
		// eb_global_styles option, decoded — EB global colors/gradients and
		// typography presets. Proxy-injected verbatim; the app resolves what a
		// global:<preset> pointer means from it. Absent until the user first
		// customizes globals (EB creates the option lazily).
		if ( 'gutenberg' === $payload['platform'] ) {
			$global_settings = self::eb_global_settings() ?? [];
			// The theme's heading sizes, measured in the canvas: an EB heading on "global"
			// typography with no EB preset for its tag renders at these (2026-10-15).
			if ( ! empty( $payload['theme_headings'] ) && empty( $global_settings['headings'] ) ) {
				$global_settings['headings'] = $payload['theme_headings'];
			}
			if ( $global_settings ) {
				$payload['global_settings'] = $global_settings;
			}
		}
		unset( $payload['theme_headings'] );

		// Dev diagnostic: dump the EXACT minified JSON we send to the app
		// (WP_DEBUG_LOG-gated via Helper::log; opt out with the filter). No
		// credentials here — auth is a header added later in
		// Helper::make_api_request, never in the body.
		if ( apply_filters( 'templately_ai_editor_log_payload', true ) ) {
			$json = wp_json_encode( $payload );
			Helper::log( 'bytes=' . strlen( $json ) . ' body=' . $json, 'ai-editor-request' );
		}

		// 90 s: a page-wide edit on gpt-6-luna can run past 60 s; the app allows a turn 90 s.
		$response = Helper::make_api_post_request( self::CLOUD_ENDPOINT, $payload, [], 90 );

		return $this->relay_cloud_response( $response );
	}

	/**
	 * GET /ai-editor/chat/{process_id} — pending-turn poll (analyze P1).
	 * Returns the standard envelope while in flight; the terminal turn once done.
	 */
	public function poll_turn() {
		$process_id = $this->get_param( 'process_id', '', 'sanitize_key' );
		if ( empty( $process_id ) ) {
			return $this->success( self::ai_envelope( 'invalid_process', __( 'Missing process id.', 'templately' ) ) );
		}

		if ( $this->is_mock_mode() ) {
			// The mock never defers, so a poll can only mean an unknown process.
			return $this->success( self::ai_envelope( 'invalid_process', __( 'Unknown process.', 'templately' ) ) );
		}

		$response = Helper::make_api_get_request( self::CLOUD_ENDPOINT . '/' . $process_id );

		return $this->relay_cloud_response( $response );
	}

	/**
	 * POST /ai-editor/apply-report — what an apply did (plugin plan task 3).
	 *
	 * Sent after a contract-2 edit that carried `turn_id`. The app checks its
	 * reply against it and answers with a `result` (the final reply and clause
	 * statuses) or, once per turn, a retry `edit`. Never billing: the turn was
	 * charged before the chat response left the app.
	 */
	public function apply_report() {
		FatalGuard::arm( 'ai-editor/apply-report' );

		$report = $this->parse_apply_report();
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		if ( $this->is_mock_mode() ) {
			return $this->success( [
				'success'         => true,
				'type'            => 'result',
				'conversation_id' => $report['conversation_id'],
				'contract'        => self::CONTRACT_VERSION,
				'reply'           => __( 'Done.', 'templately' ),
				'clauses'         => [],
			] );
		}

		$report['contract'] = self::CONTRACT_VERSION;

		if ( apply_filters( 'templately_ai_editor_log_payload', true ) ) {
			$json = wp_json_encode( $report );
			Helper::log( 'bytes=' . strlen( $json ) . ' body=' . $json, 'ai-editor-apply-report' );
		}

		// 90 s, as for a chat turn: the answer may carry a retry edit, which is an edit call.
		$response = Helper::make_api_post_request( self::APPLY_REPORT_ENDPOINT, $report, [], 90 );

		return $this->relay_cloud_response( $response );
	}

	/**
	 * The apply report as sent, checked by shape. Attribute values are the
	 * element's own settings and pass byte-faithful (HTML included), like a
	 * chat turn's target settings; everything else is ids, keys and short
	 * strings, sanitized per leaf. Malformed entries are dropped.
	 *
	 * @return array|\WP_Error
	 */
	private function parse_apply_report() {
		$conversation_id = $this->get_param( 'conversation_id', '', 'sanitize_key' );
		$turn_id         = substr( (string) $this->get_param( 'turn_id', '', 'sanitize_text_field' ), 0, 64 );
		if ( '' === $conversation_id || '' === $turn_id ) {
			return $this->error( 'invalid_request', __( 'An apply report needs its conversation and turn.', 'templately' ), 'ai-editor/apply-report', 400 );
		}

		$platform = $this->get_param( 'platform', '', 'sanitize_key' );
		if ( ! in_array( $platform, [ 'gutenberg', 'elementor' ], true ) ) {
			return $this->error( 'invalid_request', __( 'Invalid platform.', 'templately' ), 'ai-editor/apply-report', 400 );
		}

		$post_id = $this->get_param( 'post_id', 0, 'absint' );
		if ( $post_id <= 0 ) {
			return $this->error( 'invalid_request', __( 'Invalid post.', 'templately' ), 'ai-editor/apply-report', 400 );
		}

		$applied = [];
		$raw     = $this->get_param( 'applied', [], null );
		foreach ( is_array( $raw ) ? array_slice( $raw, 0, 200 ) : [] as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['id'] ) || ! is_string( $entry['id'] ) ) {
				continue;
			}
			$attributes = [];
			foreach ( is_array( $entry['attributes'] ?? null ) ? array_slice( $entry['attributes'], 0, 200, true ) : [] as $key => $value ) {
				if ( is_string( $key ) && '' !== $key ) {
					$attributes[ substr( $key, 0, 120 ) ] = $value;
				}
			}
			$rendered = [];
			foreach ( is_array( $entry['rendered'] ?? null ) ? array_slice( $entry['rendered'], 0, 200, true ) : [] as $key => $value ) {
				if ( is_string( $key ) && '' !== $key && is_scalar( $value ) ) {
					$rendered[ substr( $key, 0, 120 ) ] = mb_substr( sanitize_text_field( (string) $value ), 0, 300 );
				}
			}
			$applied[] = [
				'id'          => self::report_id( $entry['id'] ),
				'attributes'  => $attributes,
				'rendered'    => $rendered,
				'stored_only' => self::report_keys( $entry['stored_only'] ?? [] ),
			];
		}

		$styles     = $this->get_param( 'styles', [], null );
		$styles     = is_array( $styles ) ? $styles : [];
		$order      = [];
		$raw_order  = $this->get_param( 'order', [], null );
		foreach ( is_array( $raw_order ) ? array_slice( $raw_order, 0, 100, true ) : [] as $parent => $children ) {
			if ( is_string( $parent ) && '' !== $parent && is_array( $children ) ) {
				$order[ self::report_id( $parent ) ] = self::report_ids( $children );
			}
		}

		$rejected = [];
		$raw      = $this->get_param( 'rejected', [], null );
		foreach ( is_array( $raw ) ? array_slice( $raw, 0, 50 ) : [] as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['reason'] ) && is_string( $entry['reason'] ) ) {
				$rejected[] = [
					'id'     => isset( $entry['id'] ) && is_string( $entry['id'] ) ? self::report_id( $entry['id'] ) : null,
					'key'    => isset( $entry['key'] ) && is_string( $entry['key'] ) ? substr( sanitize_text_field( $entry['key'] ), 0, 120 ) : null,
					'reason' => sanitize_key( $entry['reason'] ),
				];
			}
		}

		$errors = [];
		$raw    = $this->get_param( 'errors', [], null );
		foreach ( is_array( $raw ) ? array_slice( $raw, 0, 50 ) : [] as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['reason'] ) && is_string( $entry['reason'] ) ) {
				$errors[] = [
					'id'     => isset( $entry['id'] ) && is_string( $entry['id'] ) ? self::report_id( $entry['id'] ) : null,
					'reason' => sanitize_key( $entry['reason'] ),
				];
			}
		}

		return [
			'conversation_id' => $conversation_id,
			'turn_id'         => $turn_id,
			'post_id'         => $post_id,
			'platform'        => $platform,
			'retry'           => (bool) $this->get_param( 'retry', false, 'rest_sanitize_boolean' ),
			'applied'         => $applied,
			'styles'          => [
				'settled'   => self::report_ids( $styles['settled'] ?? [] ),
				'refreshed' => self::report_ids( $styles['refreshed'] ?? [] ),
				'unchanged' => self::report_ids( $styles['unchanged'] ?? [] ),
			],
			'order'           => $order,
			'invalid_blocks'  => self::report_ids( $this->get_param( 'invalid_blocks', [], null ) ),
			'rejected'        => $rejected,
			'errors'          => $errors,
		];
	}

	/** One element id in a report, cut to 64 characters. */
	private static function report_id( string $id ): string {
		return substr( sanitize_text_field( $id ), 0, 64 );
	}

	/**
	 * A list of element ids in a report: strings only, at most 500.
	 *
	 * @param mixed $ids
	 * @return string[]
	 */
	private static function report_ids( $ids ): array {
		return is_array( $ids ) ? array_values( array_map( [ self::class, 'report_id' ], array_slice( array_filter( $ids, 'is_string' ), 0, 500 ) ) ) : [];
	}

	/**
	 * A list of attribute keys in a report: strings only, at most 200.
	 *
	 * @param mixed $keys
	 * @return string[]
	 */
	private static function report_keys( $keys ): array {
		if ( ! is_array( $keys ) ) {
			return [];
		}
		return array_values( array_map(
			static function ( $key ) {
				return substr( sanitize_text_field( $key ), 0, 120 );
			},
			array_slice( array_filter( $keys, 'is_string' ), 0, 200 )
		) );
	}

	/**
	 * Extract + sanitize + validate the turn payload (033 DTO pattern).
	 * The nested target/sections/confirm/context payloads use `null` sanitizers
	 * with manual structural validation — sanitize_text_field would corrupt them.
	 *
	 * @return array|\WP_Error
	 */
	/**
	 * Installed editor-family versions for the server-side attribute bank.
	 * Sent on EVERY turn (stateless — no handshake, no session): the server
	 * uses it both to answer `schema_coverage` and to version-mark organic
	 * `block_schemas` ingestion. Constants only — exact and free; slugs per
	 * the bank contract, `wordpress` for core blocks. Unknown/missing
	 * families are simply absent (the server treats them as not covered).
	 */
	private static function plugin_versions() {
		$versions  = [ 'wordpress' => substr( (string) get_bloginfo( 'version' ), 0, 32 ) ];
		$constants = [
			'essential-blocks'                    => 'ESSENTIAL_BLOCKS_VERSION',
			'essential-blocks-pro'                => 'ESSENTIAL_BLOCKS_PRO_VERSION',
			'elementor'                           => 'ELEMENTOR_VERSION',
			'elementor-pro'                       => 'ELEMENTOR_PRO_VERSION',
			'essential-addons-for-elementor-lite' => 'EAEL_PLUGIN_VERSION',
			'essential-addons-elementor'          => 'EAEL_PRO_PLUGIN_VERSION',
			// Families the attribute bank also holds: a plugin not reported here is
			// never served from the bank, so its widgets would miss coverage.
			'templately'                          => 'TEMPLATELY_VERSION',
			'embedpress'                          => 'EMBEDPRESS_VERSION',
			'fluentform'                          => 'FLUENTFORM_VERSION',
			'notificationx'                       => 'NOTIFICATIONX_VERSION',
		];
		foreach ( $constants as $slug => $constant ) {
			if ( defined( $constant ) && is_scalar( constant( $constant ) ) ) {
				$versions[ $slug ] = substr( (string) constant( $constant ), 0, 32 );
			}
		}
		return $versions;
	}

	/**
	 * The eb_global_styles option, decoded for the wire. EB stores each group
	 * (global_colors, gradient_colors, global_typography, …) as a JSON-encoded
	 * STRING inside the option array — decode each so the app receives real
	 * structures. Groups capped and sanitized as bounded scalar trees.
	 *
	 * @return array|null
	 */
	private static function eb_global_settings() {
		$option = get_option( 'eb_global_styles' );
		if ( ! is_array( $option ) || ! $option ) {
			return null;
		}
		$clean = [];
		foreach ( array_slice( $option, 0, 20, true ) as $group => $value ) {
			if ( ! is_string( $group ) ) {
				continue;
			}
			$decoded = is_string( $value ) ? json_decode( wp_unslash( $value ), true ) : $value;
			if ( ! is_array( $decoded ) || ! $decoded ) {
				continue;
			}
			$clean_group = self::sanitize_scalar_tree( $decoded, 4 );
			if ( is_array( $clean_group ) && $clean_group ) {
				$clean[ sanitize_key( $group ) ] = $clean_group;
			}
		}
		return $clean ? $clean : null;
	}

	private function parse_chat_request() {
		$message = $this->get_param( 'message', '', 'sanitize_textarea_field' );
		if ( empty( trim( (string) $message ) ) ) {
			return $this->error( 'invalid_request', __( 'Message is required.', 'templately' ), 'ai-editor/chat', 400 );
		}

		$platform = $this->get_param( 'platform', '', 'sanitize_key' );
		if ( ! in_array( $platform, [ 'gutenberg', 'elementor' ], true ) ) {
			return $this->error( 'invalid_request', __( 'Invalid platform.', 'templately' ), 'ai-editor/chat', 400 );
		}

		$post_id = $this->get_param( 'post_id', 0, 'absint' );
		if ( $post_id <= 0 ) {
			return $this->error( 'invalid_request', __( 'Invalid post.', 'templately' ), 'ai-editor/chat', 400 );
		}

		$payload = [
			'message'         => $message,
			'platform'        => $platform,
			'post_id'         => $post_id,
			'conversation_id' => $this->get_param( 'conversation_id', null, 'sanitize_key' ),
		];

		$target = $this->get_param( 'target', null, null );
		if ( $target !== null && $target !== '' ) {
			$target = $this->validate_target( $target );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			$payload['target'] = $target;
		}

		$sections = $this->get_param( 'sections', [], null );
		$sections = $this->validate_sections( $sections );
		if ( is_wp_error( $sections ) ) {
			return $sections;
		}
		$payload['sections'] = $sections;

		// tree (contract 2): every element of the page, one line each, for the
		// app's per-clause targeting. Optional, so a malformed entry is dropped
		// rather than failing the turn.
		$tree = $this->get_param( 'tree', null, null );
		if ( is_array( $tree ) ) {
			$clean_tree = self::sanitize_tree( $tree );
			if ( $clean_tree ) {
				$payload['tree'] = $clean_tree;
			}
		}

		$confirm = $this->get_param( 'confirm', null, null );
		if ( is_array( $confirm ) && isset( $confirm['proposal_id'] ) ) {
			$payload['confirm'] = [
				'proposal_id' => sanitize_key( (string) $confirm['proposal_id'] ),
				'accepted'    => ! empty( $confirm['accepted'] ),
			];
		}

		$context = $this->get_param( 'context', null, null );
		if ( is_array( $context ) ) {
			$clean_context = [];
			if ( ! empty( $context['rejection_reason'] ) ) {
				$clean_context['rejection_reason'] = sanitize_text_field( (string) $context['rejection_reason'] );
			}
			// Over-cap untargeted turns carry labels-only sections — the app must
			// know the settings were omitted (answer questions normally; ask for an
			// @mention only when the intent is an edit).
			if ( ! empty( $context['sections_truncated'] ) ) {
				$clean_context['sections_truncated'] = true;
			}
			// Untargeted follow-ups carry the last explicitly targeted section
			// (or site handle) so the app can resolve "it"/"that section".
			if ( ! empty( $context['previous_target'] ) && is_array( $context['previous_target'] ) ) {
				$prev_id     = $context['previous_target']['id'] ?? '';
				$prev_handle = $context['previous_target']['handle'] ?? '';
				if ( is_string( $prev_id ) && $prev_id !== '' ) {
					$clean_context['previous_target'] = [
						'id'     => sanitize_text_field( $prev_id ),
						'handle' => is_string( $prev_handle ) ? sanitize_text_field( $prev_handle ) : '',
					];
				}
			}
			// Response types this plugin can answer beyond the four (attribute bank
			// v2): `need_settings` asks for section settings a page-wide edit needs.
			if ( ! empty( $context['supports'] ) && is_array( $context['supports'] ) ) {
				$clean_context['supports'] = array_values( array_intersect( [ 'need_settings', 'tree', 'apply_report', 'plan' ], array_map( 'strval', $context['supports'] ) ) );
			}
			// Contract 3: the follow-up that runs a `plan` the app showed names it.
			if ( ! empty( $context['plan_id'] ) && is_string( $context['plan_id'] ) ) {
				$clean_context['plan_id'] = substr( sanitize_text_field( $context['plan_id'] ), 0, 64 );
			}
			// The follow-up that answers a `need_settings` reply names the
			// conversation whose waiting edit it completes.
			if ( ! empty( $context['settings_for'] ) && is_string( $context['settings_for'] ) ) {
				$clean_context['settings_for'] = substr( sanitize_text_field( $context['settings_for'] ), 0, 64 );
			}
			// On that follow-up: the ids/handles the app asked for that the page
			// no longer has (task 2b), so the app never waits on them.
			if ( ! empty( $context['missing'] ) && is_array( $context['missing'] ) ) {
				$clean_context['missing'] = array_values( array_map(
					static function ( $ref ) {
						return substr( sanitize_text_field( (string) $ref ), 0, 64 );
					},
					array_slice( array_filter( $context['missing'], 'is_string' ), 0, 100 )
				) );
			}
			if ( $clean_context ) {
				$payload['context'] = $clean_context;
			}
		}

		// block_schemas: map of block/element type => [available base attribute
		// key names], so the model can discover off-by-default controls without
		// per-instance default repetition. Plain identifiers — sanitize each.
		$block_schemas = $this->get_param( 'block_schemas', null, null );
		if ( is_array( $block_schemas ) ) {
			$clean_schemas = [];
			foreach ( $block_schemas as $type => $keys ) {
				if ( ! is_string( $type ) || ! is_array( $keys ) ) {
					continue;
				}
				$clean_keys = array_values( array_filter( array_map(
					function ( $k ) {
						return is_string( $k ) ? sanitize_text_field( $k ) : null;
					},
					$keys
				) ) );
				if ( $clean_keys ) {
					$clean_schemas[ sanitize_text_field( $type ) ] = $clean_keys;
				}
			}
			if ( $clean_schemas ) {
				$payload['block_schemas'] = $clean_schemas;
			}
		}

		// schema_enums: the block_schemas companion — type => key => [allowed
		// values] for closed-set controls (Elementor choose/select). Key names
		// alone leave the model guessing a vocabulary Elementor is not
		// self-consistent about ("right" is valid on an image widget's `align`,
		// wrong on a form's `button_align`). Same nesting depth everywhere;
		// plain identifiers, sanitized per leaf. Note '' IS a legal option
		// ("Default"), so empty leaves are kept — only non-strings are dropped.
		$schema_enums = $this->get_param( 'schema_enums', null, null );
		if ( is_array( $schema_enums ) ) {
			$clean_enums = [];
			foreach ( $schema_enums as $type => $keys ) {
				if ( ! is_string( $type ) || ! is_array( $keys ) ) {
					continue;
				}
				$clean_keys = [];
				foreach ( $keys as $key => $values ) {
					if ( ! is_string( $key ) || ! is_array( $values ) ) {
						continue;
					}
					$clean_values = [];
					foreach ( $values as $value ) {
						if ( is_string( $value ) ) {
							$clean_values[] = sanitize_text_field( $value );
						} elseif ( is_int( $value ) || is_float( $value ) ) {
							// Numerically-keyed option maps arrive as ints.
							$clean_values[] = (string) $value;
						}
					}
					if ( $clean_values ) {
						$clean_keys[ sanitize_text_field( $key ) ] = $clean_values;
					}
				}
				if ( $clean_keys ) {
					$clean_enums[ sanitize_text_field( $type ) ] = $clean_keys;
				}
			}
			if ( $clean_enums ) {
				$payload['schema_enums'] = $clean_enums;
			}
		}

		// kit_globals: design-token refs bound via __globals__ in the payload,
		// resolved to their effective kit values (colors => "#hex" string,
		// typography => the token's settings object). Lets the model reason
		// about bound keys — a relative edit needs the token's actual value.
		$kit_globals = $this->get_param( 'kit_globals', null, null );
		if ( is_array( $kit_globals ) ) {
			$clean_globals = [];
			foreach ( array_slice( $kit_globals, 0, 100, true ) as $ref => $value ) {
				if ( ! is_string( $ref ) || ! preg_match( '#^globals/[a-z_]+\?id=[\w-]+$#', $ref ) ) {
					continue;
				}
				$clean_value = self::sanitize_scalar_tree( $value, 4 );
				if ( $clean_value !== null ) {
					$clean_globals[ $ref ] = $clean_value;
				}
			}
			if ( $clean_globals ) {
				$payload['kit_globals'] = $clean_globals;
			}
		}

		// theme_headings (Gutenberg): the px size the canvas draws for each bare h1–h6 —
		// the theme's heading sizes, which a heading on global or theme typography renders
		// at. Filed under global_settings.headings in the forward.
		$theme_headings = $this->get_param( 'theme_headings', null, null );
		if ( is_array( $theme_headings ) ) {
			$clean_headings = [];
			foreach ( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ] as $tag ) {
				$size = $theme_headings[ $tag ]['fontSize'] ?? null;
				if ( is_numeric( $size ) && $size > 0 && $size <= 500 ) {
					$clean_headings[ $tag ] = [ 'fontSize' => round( (float) $size, 2 ) ];
				}
			}
			if ( $clean_headings ) {
				$payload['theme_headings'] = $clean_headings;
			}
		}

		return $payload;
	}

	/**
	 * Bounded-depth sanitizer for token values: scalars pass (strings through
	 * sanitize_text_field), arrays/objects recurse, anything else drops.
	 *
	 * @param mixed $value
	 * @param int   $depth
	 * @return mixed|null
	 */
	private static function sanitize_scalar_tree( $value, $depth ) {
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) && $depth > 0 ) {
			$clean = [];
			foreach ( $value as $k => $v ) {
				$cv = self::sanitize_scalar_tree( $v, $depth - 1 );
				if ( $cv !== null ) {
					$clean[ is_string( $k ) ? sanitize_text_field( $k ) : $k ] = $cv;
				}
			}
			return $clean;
		}
		return null;
	}

	/**
	 * @param mixed $target
	 * @return array|\WP_Error
	 */
	private function validate_target( $target ) {
		if ( ! is_array( $target ) || empty( $target['id'] ) || ! is_string( $target['id'] ) ) {
			return $this->error( 'invalid_request', __( 'Invalid target.', 'templately' ), 'ai-editor/chat', 400 );
		}
		$clean = [
			'id'     => sanitize_text_field( $target['id'] ),
			'handle' => isset( $target['handle'] ) && is_string( $target['handle'] ) ? sanitize_key( $target['handle'] ) : '',
		];
		if ( isset( $target['settings'] ) ) {
			if ( ! is_array( $target['settings'] ) ) {
				return $this->error( 'invalid_request', __( 'Invalid target settings.', 'templately' ), 'ai-editor/chat', 400 );
			}
			// Structural validation only — settings are the element's own values
			// and must reach the cloud byte-faithful (base64/HTML included).
			$clean['settings'] = $target['settings'];
		}
		return $clean;
	}

	/**
	 * The page tree as sent (contract 2): `{id, type, parent, section, text?}` per
	 * element, at most 3000. Ids and types are plain identifiers; `text` is the
	 * element's own visible text, already cut client-side, kept to 60 characters.
	 *
	 * @param array $tree
	 * @return array<int, array{id: string, type: string, parent: ?string, section: string, text?: string}>
	 */
	private static function sanitize_tree( array $tree ): array {
		$clean = [];
		foreach ( array_slice( $tree, 0, 3000 ) as $node ) {
			if ( ! is_array( $node ) || empty( $node['id'] ) || ! is_string( $node['id'] ) ) {
				continue;
			}
			$entry = [
				'id'      => substr( sanitize_text_field( $node['id'] ), 0, 64 ),
				'type'    => isset( $node['type'] ) && is_string( $node['type'] ) ? substr( sanitize_text_field( $node['type'] ), 0, 100 ) : '',
				'parent'  => isset( $node['parent'] ) && is_string( $node['parent'] ) && '' !== $node['parent'] ? substr( sanitize_text_field( $node['parent'] ), 0, 64 ) : null,
				'section' => isset( $node['section'] ) && is_string( $node['section'] ) ? substr( sanitize_text_field( $node['section'] ), 0, 64 ) : '',
			];
			if ( isset( $node['text'] ) && is_string( $node['text'] ) ) {
				$text = mb_substr( sanitize_text_field( $node['text'] ), 0, 60 );
				if ( '' !== $text ) {
					$entry['text'] = $text;
				}
			}
			// The element as the user would name it ("Heading \"About Us\""), for plans and replies.
			if ( isset( $node['label'] ) && is_string( $node['label'] ) ) {
				$label = mb_substr( sanitize_text_field( $node['label'] ), 0, 120 );
				if ( '' !== $label ) {
					$entry['label'] = $label;
				}
			}
			// What the canvas draws (font size, a button's padding): CSS lengths only.
			if ( isset( $node['drawn'] ) && is_array( $node['drawn'] ) ) {
				$drawn = [];
				foreach ( [ 'fontSize', 'padding' ] as $key ) {
					if ( isset( $node['drawn'][ $key ] ) && is_string( $node['drawn'][ $key ] ) && preg_match( '/^(?:-?[0-9.]+(?:px|em|rem|%)?\s?){1,4}$/', trim( $node['drawn'][ $key ] ) ) ) {
						$drawn[ $key ] = trim( $node['drawn'][ $key ] );
					}
				}
				if ( $drawn ) {
					$entry['drawn'] = $drawn;
				}
			}
			$clean[] = $entry;
		}
		return $clean;
	}

	/**
	 * @param mixed $sections
	 * @return array|\WP_Error
	 */
	private function validate_sections( $sections ) {
		if ( ! is_array( $sections ) ) {
			return $this->error( 'invalid_request', __( 'Invalid sections.', 'templately' ), 'ai-editor/chat', 400 );
		}
		$clean = [];
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || empty( $section['id'] ) || ! is_string( $section['id'] ) ) {
				return $this->error( 'invalid_request', __( 'Invalid section entry.', 'templately' ), 'ai-editor/chat', 400 );
			}
			$entry = [
				'id'     => sanitize_text_field( $section['id'] ),
				'handle' => isset( $section['handle'] ) && is_string( $section['handle'] ) ? sanitize_key( $section['handle'] ) : '',
				'label'  => isset( $section['label'] ) && is_string( $section['label'] ) ? sanitize_text_field( $section['label'] ) : '',
				'kind'   => isset( $section['kind'] ) && is_string( $section['kind'] ) ? sanitize_text_field( $section['kind'] ) : '',
			];
			if ( isset( $section['settings'] ) && is_array( $section['settings'] ) ) {
				$entry['settings'] = $section['settings'];
			}
			$clean[] = $entry;
		}
		return $clean;
	}

	/**
	 * Resolve the ENABLED Templately template for a location via the theme
	 * builder's condition system (research R9).
	 *
	 * @return array{id:int,platform:string,content:mixed}|null
	 */
	private function resolve_site_template( string $location ) {
		$theme_builder = function_exists( 'templately' ) ? templately()->theme_builder : null;
		if ( ! $theme_builder || empty( $theme_builder::$conditions_manager ) ) {
			return null;
		}

		$templates = $theme_builder::$conditions_manager->get_templates_by_location( $location );
		if ( empty( $templates ) ) {
			return null;
		}

		$template_id = (int) array_key_first( $templates );
		$platform    = get_post_meta( $template_id, '_templately_template_platform', true );
		$platform    = $platform === 'elementor' ? 'elementor' : 'gutenberg';

		if ( $platform === 'elementor' ) {
			$data    = get_post_meta( $template_id, '_elementor_data', true );
			$content = is_string( $data ) ? json_decode( $data, true ) : $data;
		} else {
			$post    = get_post( $template_id );
			$content = $post ? $post->post_content : '';
		}

		return [ 'id' => $template_id, 'platform' => $platform, 'content' => $content ];
	}

	/**
	 * Store a pending site-wide proposal (1h TTL). The FULL replacement content
	 * is held server-side; the client only sees the proposal id + description
	 * (U1 spike outcome: content replacement, not per-element patches —
	 * Gutenberg templates via wp_update_post, Elementor via _elementor_data).
	 *
	 * @param array $proposal { proposal_id, location, template_id, platform, content }
	 */
	private function store_proposal( array $proposal ) {
		Database::set_transient( 'ai_editor_proposal_' . $proposal['proposal_id'], $proposal, HOUR_IN_SECONDS );
	}

	/**
	 * Apply or discard a held proposal (FR-014 — after the in-chat confirm).
	 *
	 * @param array $confirm { proposal_id, accepted }
	 * @return array response payload
	 */
	private function handle_confirmation( array $confirm ) {
		$key      = 'ai_editor_proposal_' . $confirm['proposal_id'];
		$proposal = Database::get_transient( $key );

		if ( empty( $confirm['accepted'] ) ) {
			Database::set_transient( $key, false, 1 );
			return [
				'success' => true,
				'type'    => 'message',
				'reply'   => __( 'Okay - I left your site-wide template unchanged.', 'templately' ),
			];
		}

		if ( ! is_array( $proposal ) || empty( $proposal['template_id'] ) ) {
			return self::ai_envelope( 'invalid_process', __( 'That proposal has expired. Ask me again to make the change.', 'templately' ) );
		}

		$template_id = (int) $proposal['template_id'];

		if ( $proposal['platform'] === 'elementor' ) {
			update_post_meta( $template_id, '_elementor_data', wp_slash( wp_json_encode( $proposal['content'] ) ) );
			if ( class_exists( '\Elementor\Plugin' ) ) {
				// Stale generated CSS would keep rendering the old design.
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		} else {
			$updated = wp_update_post( [
				'ID'           => $template_id,
				'post_content' => wp_slash( (string) $proposal['content'] ),
			], true );
			if ( is_wp_error( $updated ) ) {
				return self::ai_envelope( 'internal_error', $updated->get_error_message() );
			}
		}

		Database::set_transient( $key, false, 1 );

		return [
			'success'     => true,
			'type'        => 'message',
			'site_wide'   => true,
			'template_id' => $template_id,
			'reply'       => sprintf(
				/* translators: %s: "header" or "footer" */
				__( 'Done - your site-wide %s was updated. This affects every page (reload the editor to see it here); it isn\'t covered by this page\'s undo.', 'templately' ),
				$proposal['location'] === 'footer' ? __( 'footer', 'templately' ) : __( 'header', 'templately' )
			),
		];
	}

	/**
	 * Normalize a cloud response into our REST response, guarding the contract
	 * version (defensive, cheap — contract §cloud).
	 *
	 * @param mixed $response
	 */
	private function relay_cloud_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $this->success( self::ai_envelope( 'remote_failed', $response->get_error_message() ) );
		}

		// Helper::make_api_*_request returns the RAW wp_remote array — the cloud
		// payload is the JSON body (which may be an envelope error on non-2xx).
		$raw_body = wp_remote_retrieve_body( $response );
		$status   = (int) wp_remote_retrieve_response_code( $response );

		// Dev diagnostic: the EXACT server response, paired with the
		// "ai-editor-request" dump above (same gate) so a turn's in/out sit
		// together in debug.log.
		if ( apply_filters( 'templately_ai_editor_log_payload', true ) ) {
			Helper::log( 'status=' . $status . ' bytes=' . strlen( $raw_body ) . ' body=' . $raw_body, 'ai-editor-response' );
		}

		/*
		 * A non-2xx upstream is a FAILURE, whatever its body happens to contain.
		 *
		 * Without this check a 405/500/502 sails straight through: the body is
		 * still valid JSON and still decodes to an array, so it was handed to
		 * `success()` and the browser received an upstream error dressed as a
		 * successful turn. Three things went wrong as a result — the panel showed
		 * a permanently blank assistant bubble (there is no `reply` key to read),
		 * a Laravel stack trace with absolute server paths crossed the wire, and
		 * RestEnvelope tripped over the shape and emitted PHP warnings on every
		 * turn. Observed live as a 502 during an editor probe run, and earlier as
		 * a 405 when the cloud route briefly stopped accepting POST.
		 */
		if ( $status < 200 || $status >= 300 ) {
			Helper::log( 'upstream ' . $status . ': ' . substr( $raw_body, 0, 500 ), 'ai-editor-response', 'error' );

			return $this->success( self::ai_envelope(
				'remote_failed',
				__( 'The Templately AI service is unavailable right now. Please try again in a moment.', 'templately' )
			) );
		}

		$response = json_decode( $raw_body, true );

		if ( ! is_array( $response ) ) {
			return $this->success( self::ai_envelope( 'internal_error', __( 'Unexpected response from the Templately app.', 'templately' ) ) );
		}

		if ( isset( $response['contract'] ) && (int) $response['contract'] > self::CONTRACT_VERSION ) {
			return $this->success( self::ai_envelope( 'remote_failed', __( 'This version of Templately is too old for the AI editor service. Please update the plugin.', 'templately' ) ) );
		}

		// A cloud confirmation_request carries the full proposed content — hold
		// it server-side and strip it from what the browser sees (FR-014).
		// Store only COMPLETE proposals: an empty content applied on confirm
		// would blank the site-wide template.
		if ( ( $response['type'] ?? '' ) === 'confirmation_request' && ! empty( $response['proposal']['proposal_id'] ) ) {
			$template_id = absint( $response['proposal']['template_id'] ?? 0 );
			$content     = $response['proposal']['content'] ?? null;
			if ( $template_id > 0 && ! empty( $content ) ) {
				$this->store_proposal( [
					'proposal_id' => sanitize_key( (string) $response['proposal']['proposal_id'] ),
					'location'    => ( $response['proposal']['location'] ?? '' ) === 'footer' ? 'footer' : 'header',
					'template_id' => $template_id,
					'platform'    => ( $response['proposal']['platform'] ?? '' ) === 'elementor' ? 'elementor' : 'gutenberg',
					'content'     => $content,
				] );
			}
			unset( $response['proposal']['content'] );
		}

		return $this->success( $response );
	}

	/**
	 * Developer-mode mock (research R11) — canned, deterministic turns so the
	 * whole plugin-side flow (validation, apply, undo, E2E) runs without the
	 * cloud endpoint. Never active in production.
	 */
	private function is_mock_mode(): bool {
		$dev_mode = defined( 'TEMPLATELY_DEVELOPER_MODE' ) && TEMPLATELY_DEVELOPER_MODE;
		return (bool) apply_filters( 'templately_ai_editor_mock', $dev_mode );
	}

	/**
	 * Mock eligibility: eligible until this user claims once (then `already_claimed`).
	 */
	private function mock_free_credit_offer(): array {
		$claimed = (bool) get_transient( self::MOCK_CLAIMED_TRANSIENT . get_current_user_id() );
		$amount  = 50;
		/* translators: %d: number of free AI credits */
		$message = sprintf( __( 'Claim %d free AI credits to try the AI Editor.', 'templately' ), $amount );

		return [
			'status' => 'success',
			'data'   => [
				'offer'    => [ 'active' => true, 'amount' => $amount, 'ends_at' => null ],
				'eligible' => ! $claimed,
				'reason'   => $claimed ? 'already_claimed' : 'eligible',
				'message'  => $claimed ? __( 'You\'ve already claimed this offer.', 'templately' ) : $message,
			],
		];
	}

	/**
	 * Mock claim: the first claim succeeds, every later one is the app's 409.
	 */
	private function mock_free_credit_claim(): WP_REST_Response {
		$key = self::MOCK_CLAIMED_TRANSIENT . get_current_user_id();

		if ( get_transient( $key ) ) {
			return new WP_REST_Response(
				[
					'success'    => false,
					'code'       => 'already_claimed',
					'message'    => __( 'You\'ve already claimed this offer.', 'templately' ),
					'status'     => 'error',
					'statusText' => 'already_claimed',
					'data'       => [ 'claimed' => false, 'reason' => 'already_claimed', 'available_credit' => null ],
				],
				409
			);
		}

		set_transient( $key, 1, DAY_IN_SECONDS );
		$amount = 50;

		return $this->success( [
			'status'  => 'success',
			/* translators: %d: number of free AI credits */
			'message' => sprintf( __( '%d free AI credits added to your account.', 'templately' ), $amount ),
			'data'    => [ 'claimed' => true, 'amount' => $amount, 'available_credit' => 200, 'reason' => 'claimed' ],
		] );
	}

	/**
	 * @param array      $payload     the sanitized chat payload
	 * @param array|null $site_target resolved header/footer template (when targeted)
	 */
	private function mock_response( array $payload, $site_target = null ): array {
		$message = strtolower( $payload['message'] );
		$base    = [
			'success'         => true,
			'conversation_id' => 'mock-conversation',
			'contract'        => self::CONTRACT_VERSION,
		];

		// Site-wide target → confirm-first proposal (FR-014). The mock's "edit"
		// appends a deterministic CTA paragraph to Gutenberg-platform templates.
		if ( $site_target ) {
			$proposal_id = 'mock-' . substr( md5( $site_target['id'] . wp_json_encode( $site_target['content'] ) ), 0, 12 );
			$content     = $site_target['content'];
			if ( $site_target['platform'] === 'gutenberg' ) {
				$content .= "\n<!-- wp:paragraph --><p>Get started</p><!-- /wp:paragraph -->";
			}
			$this->store_proposal( [
				'proposal_id' => $proposal_id,
				'location'    => $payload['target']['handle'],
				'template_id' => $site_target['id'],
				'platform'    => $site_target['platform'],
				'content'     => $content,
			] );
			return $base + [
				'type'     => 'confirmation_request',
				'reply'    => sprintf(
					/* translators: %s: "header" or "footer" */
					__( 'This updates your site-wide %s (every page, not just this one). Apply it?', 'templately' ),
					$payload['target']['handle']
				),
				'proposal' => [
					'proposal_id' => $proposal_id,
					'location'    => $payload['target']['handle'],
					'template_id' => $site_target['id'],
				],
				'credits'  => [ 'cost' => 1, 'remaining' => 149 ],
			];
		}

		if ( strpos( $message, 'credits' ) !== false ) {
			// insufficient_credits is a cloud-domain code outside the ai_envelope
			// taxonomy — same shape, built directly (contract §errors).
			return [
				'success'  => false,
				'terminal' => true,
				'code'     => 'insufficient_credits',
				'message'  => __( "You're out of credits. Top up to keep editing with AI.", 'templately' ),
			];
		}

		if ( strpos( $message, 'malformed' ) !== false ) {
			// Deliberately stale target — exercises the client reject path (FR-012).
			return $base + [
				'type'    => 'edit',
				'reply'   => __( 'Applying a change…', 'templately' ),
				'changes' => [ [ 'id' => 'mock-nonexistent-element', 'attributes' => [ 'content' => 'x' ] ] ],
				'credits' => [ 'cost' => 1, 'remaining' => 149 ],
			];
		}

		if ( preg_match( '/\badd\b|\bneed\b.*\b(section|block)\b/', $message ) ) {
			// Real-shaped dependencies so /dependencies/check enriches them with
			// is_active: a free one (installed on most stacks → ✓) + a Pro one
			// (absent → "Skipped (Pro)"). Exercises the install→import checklist
			// without forcing a real install in E2E.
			$deps = [
				[
					'id'                   => 1,
					'name'                 => 'Essential Blocks',
					'plugin_file'          => 'essential-blocks/essential-blocks.php',
					'plugin_original_slug' => 'essential-blocks',
					'is_pro'               => false,
					'link'                 => 'https://wordpress.org/plugins/essential-blocks/',
				],
				[
					'id'                   => 2,
					'name'                 => 'Essential Blocks Pro',
					'plugin_file'          => 'essential-blocks-pro/essential-blocks-pro.php',
					'plugin_original_slug' => 'essential-blocks-pro',
					'is_pro'               => true,
					'link'                 => 'https://essential-blocks.com/upgrade/',
				],
			];

			// insert.anchor_id is an id we "sent" this turn — the pinned/selected
			// target when there is one, else null (the user hasn't said where; §4).
			$anchor_id = $payload['target']['id'] ?? null;
			$insert    = $anchor_id ? [ 'anchor_id' => $anchor_id, 'placement' => 'after' ] : null;

			return $base + [
				'type'   => 'library_results',
				'reply'  => __( 'Here are some matching sections from the Templately library:', 'templately' ),
				'insert' => $insert,
				'items'  => [
					[ 'id' => 990001, 'name' => 'Testimonials 01', 'type' => 'block', 'price' => 0, 'is_pro' => false, 'thumbnail' => 'https://placehold.co/300x200', 'preview_url' => 'https://placehold.co/1200x800', 'dependencies' => $deps ],
					[ 'id' => 990002, 'name' => 'Testimonials 02 (Pro)', 'type' => 'block', 'price' => 39, 'is_pro' => true, 'thumbnail' => 'https://placehold.co/300x200', 'preview_url' => 'https://placehold.co/1200x800', 'buy_url' => 'https://templately.com/#pricing', 'dependencies' => [] ],
					[ 'id' => 990003, 'name' => 'FAQ 01', 'type' => 'block', 'price' => 0, 'is_pro' => false, 'thumbnail' => 'https://placehold.co/300x200', 'dependencies' => [] ],
				],
				'credits' => [ 'cost' => 1, 'remaining' => 149 ],
			];
		}

		// `set <key> to "<value>"` → a change for the first submitted element that
		// carries <key>. Drives the T039 coverage matrices: the E2E picks a REAL
		// attribute per block/widget type and asserts the full pipeline
		// (serialize → validate → apply → undo) without per-type mock knowledge.
		if ( preg_match( '/set ([a-zA-Z0-9_]+) to "([^"]*)"/', $payload['message'], $m ) ) {
			$element_id = $this->mock_find_element_with_key( $payload, $m[1] );
			if ( $element_id ) {
				return $base + [
					'type'    => 'edit',
					'reply'   => __( 'Done - I updated it.', 'templately' ),
					'changes' => [ [ 'id' => $element_id, 'attributes' => [ $m[1] => $m[2] ] ] ],
					'credits' => [ 'cost' => 1, 'remaining' => 149 ],
				];
			}
			return $base + [
				'type'  => 'message',
				'reply' => __( "I couldn't find that setting on the targeted section.", 'templately' ),
			];
		}

		// "reorder"/"move" → a STRUCTURAL move (contracts/move-operation.md).
		// Emits a moves-only turn: reordering is not an attribute write, and the
		// client must accept a change-set with no `changes` at all.
		if ( strpos( $message, 'reorder' ) !== false || strpos( $message, 'move ' ) !== false ) {
			$ids = $this->mock_movable_children( $payload );
			if ( count( $ids ) >= 2 ) {
				return $base + [
					'type'    => 'edit',
					'reply'   => __( 'Moved it into place.', 'templately' ),
					// Last child to the front — one move, not a two-write swap.
					'moves'   => [ [ 'id' => end( $ids ), 'index' => 0 ] ],
					'credits' => [ 'cost' => 1, 'remaining' => 149 ],
				];
			}
			return $base + [
				'type'  => 'message',
				'reply' => __( 'There is nothing to reorder in that section.', 'templately' ),
			];
		}

		// "rewrite" → a MULTI-element change-set (drives the single-undo E2E: one
		// undo must revert every patched field at once — research R3).
		$limit     = strpos( $message, 'rewrite' ) !== false ? 2 : 1;
		$editables = $this->mock_find_editables( $payload, $limit );
		if ( ! empty( $editables ) ) {
			$targeted = ! empty( $payload['target'] );
			$changes  = [];
			foreach ( $editables as $i => $editable ) {
				$changes[] = [
					'id'         => $editable['id'],
					'attributes' => [
						$editable['key'] => $i === 0
							? __( 'Shorter, punchier headline', 'templately' )
							: __( 'Rewritten supporting copy.', 'templately' ),
					],
				];
			}
			return $base + [
				'type'    => 'edit',
				'reply'   => __( 'Done - I updated the text.', 'templately' ),
				'changes' => $changes,
				'credits' => [ 'cost' => $targeted ? 1 : 2, 'remaining' => $targeted ? 149 : 148 ],
			];
		}

		return $base + [
			'type'    => 'message',
			'reply'   => __( 'Which section do you mean? Try @-mentioning one.', 'templately' ),
			'credits' => [ 'cost' => 1, 'remaining' => 149 ],
		];
	}

	/**
	 * Find up to $limit string text-ish attributes in the submitted settings so
	 * the mock's change-set survives real client-side validation.
	 *
	 * @return array<int, array{id:string,key:string}>
	 */
	private function mock_find_editables( array $payload, int $limit = 1 ): array {
		$pools = [];
		if ( ! empty( $payload['target']['settings'] ) ) {
			$pools[] = $payload['target']['settings'];
		}
		foreach ( $payload['sections'] as $section ) {
			if ( ! empty( $section['settings'] ) ) {
				$pools[] = $section['settings'];
			}
		}

		$found = [];
		foreach ( $pools as $pool ) {
			// Gutenberg roots at `blocks`, Elementor at `elements`.
			$blocks = $pool['blocks'] ?? $pool['elements'] ?? ( is_array( $pool ) ? $pool : [] );
			$this->mock_scan_blocks( $blocks, $limit, $found );
			if ( count( $found ) >= $limit ) {
				break;
			}
		}
		return $found;
	}

	/**
	 * Ids of the deepest sibling group in the submitted settings — the set a
	 * reorder can actually act on. Walks to the first node with 2+ children so
	 * the mock moves real siblings (a move between different parents would be a
	 * different, unrelated assertion).
	 *
	 * @return array<int, string>
	 */
	private function mock_movable_children( array $payload ): array {
		$pool = $payload['target']['settings'] ?? null;
		if ( ! $pool && ! empty( $payload['sections'][0]['settings'] ) ) {
			$pool = $payload['sections'][0]['settings'];
		}
		if ( ! is_array( $pool ) ) {
			return [];
		}
		$nodes = $pool['blocks'] ?? $pool['elements'] ?? [];
		while ( is_array( $nodes ) ) {
			$ids = [];
			foreach ( $nodes as $node ) {
				$id = $node['id'] ?? $node['clientId'] ?? null;
				if ( is_string( $id ) && '' !== $id ) {
					$ids[] = $id;
				}
			}
			if ( count( $ids ) >= 2 ) {
				return $ids;
			}
			$first = $nodes[0] ?? null;
			if ( ! is_array( $first ) ) {
				return $ids;
			}
			$nodes = $first['elements'] ?? $first['innerBlocks'] ?? null;
		}
		return [];
	}

	/**
	 * First submitted element (target pool first) carrying $key in its
	 * attributes/settings — the T039 matrix locator.
	 *
	 * @return string|null element id
	 */
	private function mock_find_element_with_key( array $payload, string $key ) {
		$pools = [];
		if ( ! empty( $payload['target']['settings'] ) ) {
			$pools[] = $payload['target']['settings'];
		}
		foreach ( $payload['sections'] as $section ) {
			if ( ! empty( $section['settings'] ) ) {
				$pools[] = $section['settings'];
			}
		}
		foreach ( $pools as $pool ) {
			$blocks = $pool['blocks'] ?? $pool['elements'] ?? ( is_array( $pool ) ? $pool : [] );
			$found  = $this->mock_scan_for_key( $blocks, $key );
			if ( $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * @param mixed $blocks
	 * @return string|null
	 */
	private function mock_scan_for_key( $blocks, string $key ) {
		if ( ! is_array( $blocks ) ) {
			return null;
		}
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$id         = $block['clientId'] ?? $block['id'] ?? null;
			$attributes = $block['attributes'] ?? $block['settings'] ?? [];
			if ( $id && is_array( $attributes ) && array_key_exists( $key, $attributes ) ) {
				return (string) $id;
			}
			$found = $this->mock_scan_for_key( $block['innerBlocks'] ?? $block['elements'] ?? null, $key );
			if ( $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * @param mixed $blocks
	 * @param int   $limit
	 * @param array $found  accumulator of {id, key} (unique per element)
	 */
	private function mock_scan_blocks( $blocks, int $limit, array &$found ) {
		if ( ! is_array( $blocks ) || count( $found ) >= $limit ) {
			return;
		}
		foreach ( $blocks as $block ) {
			if ( count( $found ) >= $limit ) {
				return;
			}
			if ( ! is_array( $block ) ) {
				continue;
			}
			$id         = $block['clientId'] ?? $block['id'] ?? null;
			$attributes = $block['attributes'] ?? $block['settings'] ?? [];
			if ( $id && is_array( $attributes ) ) {
				foreach ( [ 'content', 'text', 'title', 'label', 'editor' ] as $key ) {
					if ( isset( $attributes[ $key ] ) && is_string( $attributes[ $key ] ) ) {
						$found[] = [ 'id' => (string) $id, 'key' => $key ];
						break;
					}
				}
			}
			$this->mock_scan_blocks( $block['innerBlocks'] ?? $block['elements'] ?? null, $limit, $found );
		}
	}
}
