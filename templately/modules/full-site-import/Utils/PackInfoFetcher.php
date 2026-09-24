<?php

namespace Templately\Modules\FullSiteImport\Utils;

use Templately\Utils\Helper;
use Templately\Utils\Response\ResponseNormalizer;
use WP_Error;

/**
 * Single source of the pack import-info fetch (`v2/import/info/pack/{id}`).
 *
 * De-duplicates the identical `fetch_pack_info_from_api()` that previously lived as a
 * private copy in BOTH `Ajax\PackInfoController` and `REST\GlobalSettings` (see the
 * "kept as its own copy" note the GlobalSettings B8 change left behind). All three
 * consumers now delegate here:
 *   - `Ajax\PackInfoController::import_info()` / `::import_global_settings()` (deprecated shims)
 *   - `REST\GlobalSettings::import_global_settings()` (POST /import/global-settings, FR-018/B8)
 *   - `REST\PackInfo::import_info()`               (GET  /import/info, FR-018)
 *
 * The logic is byte-equivalent in effect to the two former private copies — this is a
 * pure consolidation, no behavior change. Error classification is delegated to the
 * central `ResponseNormalizer` (spec 043).
 */
class PackInfoFetcher {

	/**
	 * Fetch a pack's import-info payload from the Templately API.
	 *
	 * @param int|string $id   Pack id.
	 * @param mixed       $isAi AI flag, in whatever type the caller happens to hold it
	 *                          (PHP boolean, `"true"`/`"1"`/`1`, or falsy) — normalized here
	 *                          to the literal string `'true'`/`'false'` via
	 *                          `rest_sanitize_boolean()` before being sent as the
	 *                          `x-templately-is-ai` header. This is NOT cosmetic: the
	 *                          upstream API only includes `credit_balance`/`estimated_cost`
	 *                          in the response when it sees the literal string `"true"`. A
	 *                          raw PHP boolean `true` stringifies to `"1"` when WP's HTTP
	 *                          layer serializes the header, which upstream treats as falsy
	 *                          and silently omits the credit fields — that regression is
	 *                          what disabled the AI sidebar's credit display/generation
	 *                          entirely. Normalizing to a string here fixes the REST route
	 *                          AND the deprecated ajax shims uniformly, regardless of what
	 *                          each caller passes in.
	 *
	 * @return array|WP_Error Decoded response body, or WP_Error on transport / non-200 / API error.
	 */
	public function fetch( $id, $isAi ) {
		$extra_headers = [
			'x-templately-is-ai' => rest_sanitize_boolean( $isAi ) ? 'true' : 'false',
		];
		$response = Helper::make_api_get_request( "v2/import/info/pack/$id", [], $extra_headers, 30 );

		// 043 FR-003: one normalizer classifies transport failures AND non-200
		// bodies. It replaces `extract_error_from_response()`, which returned
		// `mixed` — sometimes a string, sometimes a whole decoded body — and so
		// leaked upstream internals straight into a user-facing message.
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) != 200 ) {
			return ResponseNormalizer::normalize( $response )->error();
		}
		// Retrieve data from the response body.
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['error'] ) ) {
			return new WP_Error( 'api_error', $data['error'] );
		}

		return $data;
	}
}
