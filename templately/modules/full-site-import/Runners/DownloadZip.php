<?php

namespace Templately\Modules\FullSiteImport\Runners;

use Exception;
use Templately\Modules\FullSiteImport\Utils\Form;
use Templately\Modules\FullSiteImport\Runners\BaseRunner;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Utils\Helper;
use Templately\Utils\Response\ResponseNormalizer;

class DownloadZip extends BaseRunner {
	/**
	 * @var string
	 */
	private $download_key;

	/**
	 * @var string
	 */
	private $filePath;

	public function get_name(): string {
		return 'downloadzip';
	}

	public function get_label(): string {
		return __( 'Download Zip', 'templately' );
	}

	public function should_log(): bool {
		return true;
	}

	public function get_action(): string {
		return 'eventLog';
	}

	public function log_message(): string {
		return __( 'Updating customizer settings.', 'templately' );
	}

	public function should_run( $data, $imported_data = [] ): bool {
		$params = $this->origin->get_request_params();
		return ! empty( $params['title'] ) || !empty( $params['slogan'] );
	}

	public function import( $data, $imported_data ): array {
        /**
         * Download the zip
         */
        $this->download_zip( $_id );

        SessionData::mark_step_complete($this->session_id, 'download_zip');
        $this->sse_message( [
            'type'    => 'continue',
            'action'  => 'continue',
            'info'    => 'download_zip',
            'results' => __METHOD__ . '::' . __LINE__,
        ] );

		return  [ 'customizer' => $customizer ];
	}

	/**
	 * @throws Exception
	 */
	private function download_zip( $id ) {
		$this->sse_log( 'download', __( 'Downloading Template Pack', 'templately' ), 1 );

		// Forward the host platform (e.g. 'ai-builder') so the cloud scopes the
		// pack download the same way as the REST/AJAX calls — without it this
		// binary download bypasses Helper::make_api_request() and the cloud
		// defaults to 'templately', re-gating AI Builder packs. Sourced from the
		// request param to match the param-only resolution in Helper.
		$requested_platform = isset( $_REQUEST['requested_platform'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_text_field( wp_unslash( $_REQUEST['requested_platform'] ) )
			: 'templately';

		// The download used to leave NO trace: a run that stopped here showed one
		// "Downloading Template Pack" line and nothing else — no size, no status, no
		// timing, no indication whether the request was even made. That is the state this
		// log exists to make readable.
		Helper::log(
			sprintf( 'download_zip: requesting pack %s (session=%s)', $id, $this->session_id ),
			'download_zip',
			'info'
		);

		// `connection_aborted()` is only truthful after output has been flushed, which the
		// SSE stream does constantly — so a shutdown handler can tell "the browser hung up"
		// apart from "PHP died", which is the whole question when a run ends in silence.
		$download_started = microtime( true );
		$session_id       = $this->session_id;
		register_shutdown_function( function () use ( $id, $session_id, $download_started ) {
			if ( defined( 'TEMPLATELY_DOWNLOAD_ZIP_DONE' ) ) {
				return;
			}

			Helper::log(
				sprintf(
					'download_zip: request ENDED before the pack was saved after %.1fs (pack=%s, session=%s, connection_aborted=%d, last_error=%s)',
					microtime( true ) - $download_started,
					$id,
					$session_id,
					connection_aborted(),
					wp_json_encode( error_get_last() )
				),
				'download_zip',
				'error'
			);
		} );

		$response = wp_remote_get( $this->get_api_url( "v2", "import/pack/$id" ), [
			'timeout' => 30,
			'headers' => [
				'Content-Type'                    => 'application/json',
				'Authorization'                   => 'Bearer ' . $this->api_key,
				'x-templately-ip'                 => Helper::get_ip(),
				'x-templately-url'                => home_url('/'),
				'x-templately-version'            => TEMPLATELY_VERSION,
				'x-templately-requested-platform' => $requested_platform,
			]
		]);

		// The pack is a ZIP: FR-012 raw passthrough, so the body stays bytes and
		// `download-key` stays readable off the raw response. The normalizer is
		// used only to CLASSIFY, matching Concerns\DownloadsPack — these two carry
		// the same download logic, and letting only one of them learn the contract
		// is how they drift apart again.
		$this->download_key = wp_remote_retrieve_header($response, 'download-key');

		Helper::log(
			sprintf(
				'download_zip: pack %s answered %s in %.1fs (%s bytes, type=%s, download-key=%s)',
				$id,
				is_wp_error( $response ) ? 'WP_Error: ' . $response->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ),
				microtime( true ) - $download_started,
				is_wp_error( $response ) ? '0' : strlen( (string) wp_remote_retrieve_body( $response ) ),
				is_wp_error( $response ) ? '-' : (string) wp_remote_retrieve_header( $response, 'content-type' ),
				$this->download_key ? 'present' : 'missing'
			),
			'download_zip',
			is_wp_error( $response ) ? 'error' : 'info'
		);

		$normalized = ResponseNormalizer::normalize($response, [
			'raw'          => true,
			'side_effects' => false,
		]);

		if ($normalized->is_error()) {
			$error = $normalized->error();

			if ($error->is_retryable()) {
				$this->throw_retryable(__('Template pack download failed. ', 'templately') . $error->message());
			}

			$support_message = '';
			if (strpos($error->message(), Helper::web_url( '', [ 'support' => 'open' ] )) === false) {
				$support_message = sprintf(__(" Please try again or contact <a href='%s' target='_blank'>support</a>.", "templately"), Helper::web_url( '', [ 'support' => 'open' ] ));
			}

			$this->throw_non_retryable($error->message() . $support_message);
		}

		$this->sse_log('download', __('Downloading Template Pack', 'templately'), 57);

		// $session_id       = uniqid();
		$this->dir_path   = $this->tmp_dir . $this->session_id . DIRECTORY_SEPARATOR;
		$this->filePath   = $this->tmp_dir . "{$this->session_id}.zip";
		// $this->session_id = $session_id;

		SessionData::set($this->session_id, 'session_id', $this->session_id);
		SessionData::set($this->session_id, 'dir_path', $this->dir_path);
		SessionData::set($this->session_id, 'download_key', $this->download_key);

		$written = file_put_contents($this->filePath, $response['body']); // phpcs:ignore

		Helper::log(
			sprintf( 'download_zip: wrote %s bytes to %s', var_export( $written, true ), $this->filePath ),
			'download_zip',
			false === $written ? 'error' : 'info'
		);

		if ($written) {
			// The shutdown handler above only fires when the pack never made it to disk.
			if ( ! defined( 'TEMPLATELY_DOWNLOAD_ZIP_DONE' ) ) {
				define( 'TEMPLATELY_DOWNLOAD_ZIP_DONE', true );
			}

			$this->sse_log('download', __('Downloading Template Pack', 'templately'), 100);

			$this->unzip();

			Helper::log( sprintf( 'download_zip: unzipped into %s', $this->dir_path ), 'download_zip', 'info' );
		} else {
			$this->throw_retryable(__('Downloading Failed. Please try again', 'templately'));
		}
	}

}