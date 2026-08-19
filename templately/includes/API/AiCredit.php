<?php

namespace Templately\API;

class AiCredit extends API {

	/**
	 * AI Studio — credit balance and usage history.
	 *
	 * Both upstream operations already accept `api_key` via the backend's
	 * `Helper::tokenOrApi()`, so unlike the workspace/subscription operations
	 * these needed no schema change to be callable from the plugin.
	 *
	 * Note this is the GraphQL surface, not the `v2/ai/available-credits` REST
	 * endpoint that `LogoGeneration` uses — that one returns only a single
	 * `available_credit` integer, while AI Studio needs the full stats payload
	 * (subscription vs purchased split, totals, plan interval).
	 */
	public function register_routes() {
		$this->get( 'ai-credit/stats', [ $this, 'get_stats' ] );
		$this->get( 'ai-credit/history', [ $this, 'get_history' ] );
	}

	/**
	 * Credit balance.
	 *
	 * `stats` comes back as a JSON *string* which the client parses — same
	 * contract the web dashboard's `available-credit.tsx` works against. It is
	 * decoded here so the React side gets a real object and does not have to
	 * repeat the parse-and-guard dance.
	 */
	public function get_stats() {
		$response = $this->http()->query( 'getAvailableCredit', 'stats', [
			'api_key' => $this->api_key,
		] )->post();

		if ( is_wp_error( $response ) ) {
			return $this->error( 'invalid_ai_credit_stats_response', $response->get_error_message(), 'ai-credit/stats', 400 );
		}

		$stats = [];

		if ( ! empty( $response['stats'] ) ) {
			$decoded = json_decode( $response['stats'], true );

			if ( is_array( $decoded ) ) {
				$stats = $decoded;
			}
		}

		return $this->success( [ 'stats' => $stats ] );
	}

	/**
	 * Paginated credit usage history.
	 */
	public function get_history() {
		$page     = $this->get_param( 'page', 1, 'intval' );
		// Clamped: `per_page` reaches the upstream API verbatim, and an unbounded
		// value lets any caller ask it for the whole table in one request.
		$per_page = max( 1, min( 100, $this->get_param( 'per_page', 10, 'intval' ) ) );
		$page     = max( 1, $page );

		$response = $this->http()->mutation(
			'getAiCreditUseHistory',
			'current_page, total_page, data{ id, template_name, template_type, credit_cost, created_at }',
			[
				'api_key'  => $this->api_key,
				'page'     => $page,
				'per_page' => $per_page,
			]
		)->post();

		if ( is_wp_error( $response ) ) {
			return $this->error( 'invalid_ai_credit_history_response', $response->get_error_message(), 'ai-credit/history', 400 );
		}

		return $this->success( $response );
	}
}
