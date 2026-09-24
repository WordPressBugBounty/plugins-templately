<?php

namespace Templately\Modules\FullSiteImport\Concerns;

use Elementor\Plugin;
use Error;
use Exception;
use Templately\API\API;
use Templately\Modules\FullSiteImport\Exception\FatalErrorException;
use Templately\Modules\FullSiteImport\Exception\RetryableErrorException;
use Templately\Modules\FullSiteImport\Exception\UnknownErrorException;
use Templately\Modules\FullSiteImport\Utils\LogHandler;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Modules\FullSiteImport\Utils\AIUtils;
use Templately\Utils\Helper;
use Templately\Utils\Options;
use Templately\Utils\Response\AjaxResponder;
use Templately\Utils\Response\ErrorCode;

/**
 * HandlesAiEndpoints — extracted verbatim from FullSiteImport (behavior-preserving).
 * Composed back into FullSiteImport via `use`; $this and method resolution unchanged.
 */
trait HandlesAiEndpoints {
	/**
	 * Frozen AJAX action `templately_pack_ai_get_json` — a thin alias to the
	 * single retrieval behavior (D7). Kept so the action name contract is stable.
	 */
	public function ai_get_json() {
		$this->retrieve_ai_content();
	}

	/**
	 * The ONE content-retrieval behavior (FR-002/D7): read whatever AI content is
	 * on disk for the process and respond with the standard envelope (FR-003).
	 * Both `ai_get_json` (read) and `ai_poll_template` (poll-then-read) flow
	 * through here. Responses are ADDITIVE — the existing `process_id`/`templates`/
	 * `is_last_part` payload is preserved, with `{success,terminal,code,message}`
	 * merged in (envelope sent INSIDE wp_send_json's data, where the client's poll
	 * loop already reads `terminal` from the error path).
	 */
	private function retrieve_ai_content() {
		// read json data from post body
		$body = file_get_contents('php://input');
		$data = json_decode($body, true);

		if(empty($data['ai_page_ids'])){
			AjaxResponder::error(ErrorCode::AI_INVALID_PROCESS, 'Invalid ai_page_ids');
			return;
		}

		if(!isset($_GET['session_id'])){
			AjaxResponder::error(ErrorCode::AI_INVALID_PROCESS, 'Invalid session_id');
			return;
		}

		$session_id  = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : null;
		$process_id  = $data['process_id'] ?? null;
		$ai_page_ids = $data['ai_page_ids'] ?? null;

		$this->request_params = $this->get_session_data();
		try {
			$this->manifest = $this->read_manifest($this->request_params['dir_path']);
		} catch (\Exception $th) {
			AjaxResponder::error(ErrorCode::AI_INTERNAL_ERROR, $th->getMessage());
		}

		if(!empty($session_id) && empty($process_id)){
			if ( !empty($this->request_params['process_id']) ){
				$process_id = $this->request_params['process_id'] ?? null;
			} else {
				$process_id = AIUtils::get_ai_process_id_by_session_id($session_id);
			}
		}

		if(empty($process_id)){
			AjaxResponder::error(ErrorCode::AI_INVALID_PROCESS, 'Invalid process_id');
			return;
		}

		$process_data = AIUtils::get_ai_process_data_by_process_id($process_id);
		if (!empty($process_data['preview_error'])) {
			// Terminal remote failure persisted on the process row.
			AjaxResponder::error(ErrorCode::AI_REMOTE_FAILED, $process_data['preview_error']);
		}

		// Use the new common function to read AI template data directly
		$result = AIUtils::read_ai_template_data($session_id, $ai_page_ids, $this->request_params['dir_path']);

		// Check if this is called from polling endpoint and include additional data
		$response_data = ['process_id' => $process_id, 'templates' => $result];

		$is_last_part = null;
		if (isset($this->polling_is_last_part)) {
			$is_last_part = $this->polling_is_last_part;
			$response_data['is_last_part'] = $is_last_part;

			// Clean up the polling property
			unset($this->polling_is_last_part);
		}

		// Code reflects completeness: all pages on disk (or remote says last part)
		// → ok (terminal); otherwise not_ready (the client keeps polling).
		$total_pages = 0;
		if (is_array($ai_page_ids)) {
			foreach ($ai_page_ids as $ids) {
				$total_pages += is_array($ids) ? count($ids) : 0;
			}
		}
		$ready = ! empty($is_last_part) || ($total_pages > 0 && count($result) >= $total_pages);

		// The one place the whole generation's progress is actually countable. Logged on
		// every poll at debug, and raised to a warning for the state that strands the
		// client: the remote says this was the last part, yet pages are still unwritten.
		// The client turns exactly that into "N pages still missing when process marked as
		// complete" and stops — so without this line the only record of WHY is a message in
		// the browser.
		$on_disk = is_array($result) ? count($result) : 0;
		$stranded = ! empty($is_last_part) && $total_pages > 0 && $on_disk < $total_pages;
		Helper::log(
			sprintf(
				'retrieve_ai_content: %s — %d/%d page(s) on disk, is_last_part=%s%s',
				$process_id,
				$on_disk,
				$total_pages,
				null === $is_last_part ? '(not polled)' : ( $is_last_part ? 'yes' : 'no' ),
				$stranded ? ' — STRANDED: marked complete with pages missing' : ''
			),
			'retrieve_ai_content',
			$stranded ? 'warning' : 'debug'
		);

		AjaxResponder::success(API::ai_envelope($ready ? 'ok' : 'not_ready', '', $response_data));
	}

	/**
	 * AJAX handler for polling AI template generation status on local sites
	 * Makes GET request to API endpoint and returns data in same format as ai_get_json()
	 */
	public function ai_poll_template() {
		// Read JSON data from post body
		$body = file_get_contents('php://input');
		$data = json_decode($body, true);

		$process_id = $data['process_id'] ?? null;
		$ai_page_ids = $data['ai_page_ids'] ?? null;

		if(empty($process_id)){
			AjaxResponder::error(ErrorCode::AI_INVALID_PROCESS, 'Invalid process_id');
			return;
		}

		// Validate and get AI process data using centralized method
		$process_data = AIUtils::validate_and_get_process_data($process_id);
		if (is_wp_error($process_data)) {
			$this->retrieve_ai_content();
			return;
		}

		$session_id = $process_data['session_id'];
		$ai_page_ids = $process_data['ai_page_ids'];

		// Use the common polling function to handle all template processing
		$polling_result = AIUtils::poll_for_template($process_id, $session_id, $ai_page_ids);

		// Terminal remote error (401/403/404): stop polling and report a real error.
		// Still HTTP 200 (AjaxResponder always is), but the body is the standard
		// error envelope now, so it REJECTS on the client instead of resolving with
		// `success:false`. The poll loop stops on the error's `retryable:false`
		// rather than on a bespoke `terminal` flag — see pollAIContent's
		// onAttemptError. FR-003.
		if (is_wp_error($polling_result)) {
			$error_data = $polling_result->get_error_data();
			AjaxResponder::error(ErrorCode::AI_REMOTE_FAILED, $polling_result->get_error_message(), [
				'status'  => isset($error_data['status']) ? $error_data['status'] : null,
				'context' => [ 'process_id' => $process_id ],
			]);
			return;
		}

		if (!$polling_result) {
			// Transient miss — fall back to the read path (keep waiting).
			$this->retrieve_ai_content();
			return;
		}

		// After polling and processing templates, read + respond via the single
		// retrieval behavior (reuses all the existing logic without duplication).
		$this->retrieve_ai_content();
	}


}
