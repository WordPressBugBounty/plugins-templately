<?php

namespace Templately\Modules\FullSiteImport\Concerns;

use Elementor\Plugin;
use Error;
use Exception;
use Templately\Modules\FullSiteImport\Exception\FatalErrorException;
use Templately\Modules\FullSiteImport\Exception\RetryableErrorException;
use Templately\Modules\FullSiteImport\Exception\UnknownErrorException;
use Templately\Modules\FullSiteImport\Runners\Finalizer;
use Templately\Modules\FullSiteImport\Utils\LogHandler;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Modules\FullSiteImport\Utils\AIUtils;
use Templately\Utils\Helper;
use Templately\Utils\Options;

/**
 * ReportsStatus — extracted verbatim from FullSiteImport (behavior-preserving).
 * Composed back into FullSiteImport via `use`; $this and method resolution unchanged.
 */
trait ReportsStatus {
	public function handle_import_status($status, $description = '') {
		if ($this->is_import_status_handled === $status) {
			Helper::log("Import status already handled: $status");
			return null;
		}
		$this->is_import_status_handled = $status;

		$download_key = $this->download_key;

		$headers = [
			'Content-Type'     => 'application/json',
			'Authorization'    => 'Bearer ' . $this->api_key,
			'download_key'     => $download_key,
			'download-key'     => $download_key,
			'x-templately-ip'  => Helper::get_ip(),
			'x-templately-url' => home_url('/'),
		];


		$request_params = $this->get_session_data();
		if(isset($request_params['process_id']) && !empty($request_params['ai_page_ids'])){
			$updated_ids     = AIUtils::get_processed_pages_data($request_params['process_id']);
			$updated_pages   = $updated_ids['pages'] ?? [];
			$ai_page_ids     = array_reduce($request_params['ai_page_ids'], 'array_merge', array());

			$headers['x-templately-ai-process-id']      = $request_params['process_id'];
			$headers['x-templately-ai-requested-pages'] = implode(',', $ai_page_ids);
			$headers['x-templately-ai-updated-pages']   = implode(',', array_keys($updated_pages));
			$headers['x-templately-ai-missing-pages']   = implode(',', array_diff($ai_page_ids, array_keys($updated_pages)));
			$headers['x-templately-ai-credit-cost']     = $updated_ids['credit_cost'] ?? null;

			// Chat handoff (spec 042 FR-026): the backend keys a generated site by the
			// conversation uuid — no download_key is minted for AI bundles. Forward it
			// so the success/failed endpoints can flip the conversation status
			// (delivered / error) and persist the failure. The uuid was stored on the
			// process data when the import was staged (chatbot-import-prepare).
			$ai_process_data = AIUtils::get_ai_process_data_by_process_id($request_params['process_id']);
			if (!empty($ai_process_data['chat_id'])) {
				$headers['x-templately-ai-chat-uuid'] = $ai_process_data['chat_id'];
			}
		}


		$extra_headers = $headers;

		// Seconds the user spent idling in the customizer step, accumulated by
		// the React import wizard. Sent so the backend can exclude it from the
		// import-time metric (a customizer left open for hours would otherwise
		// inflate the count). Absent for older clients, which the backend
		// treats as zero idle.
		$customizer_idle = isset($request_params['customizer_idle']) ? (int) $request_params['customizer_idle'] : 0;

		$normalized = null;

		if ($status === 'success') {
			$body = ['type' => 'pack', 'customizer_idle' => $customizer_idle];
			$normalized = Helper::api_post('v1/import/success', $body, $extra_headers);
		} elseif ($status === 'failed') {
			$body = ['type' => 'pack', 'description' => $description ?: "Something Went wrong.....", 'customizer_idle' => $customizer_idle];
			$normalized = Helper::api_post('v1/import/failed', $body, $extra_headers);
		}

		if (null === $normalized) {
			return null;
		}

		// 043 — reporting import status is best-effort telemetry: a failure here must
		// never derail an import that already finished. It is logged with a real code
		// (previously only transport errors were logged at all — a 500 from the cloud
		// was silently treated as success and its body decoded as a result).
		if ($normalized->is_error()) {
			$error = $normalized->error();
			Helper::log("Import status report failed: {$error->code()} — {$error->message()}", 'ReportsStatus', 'warning');

			return null;
		}

		SessionData::set($this->session_id, 'is_import_status_handled', $this->is_import_status_handled);

		return $normalized->payload();
	}

}
