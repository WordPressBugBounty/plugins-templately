<?php

namespace Templately\Core\Importer\Runners;

use Exception;
use Templately\Builder\Factory\TemplateFactory;
use Templately\Core\Importer\FullSiteImport;
use Templately\Core\Importer\Import;
use Templately\Core\Importer\LogHelper;
use Templately\Core\Importer\Utils\ImportHelper;
use Templately\Core\Importer\Utils\Utils;

abstract class BaseRunner {
	use LogHelper;
	use Loop;

	const META_SESSION_KEY = '_templately_import_session_id';
	/**
	 * @var FullSiteImport
	 */
	protected $dev_mode;
	protected $origin;
	protected $platform;
	protected $manifest;
	protected $prv_dir;
	protected $dir_path;
	protected $session_id;
	/**
	 * @var array|mixed
	 */
	public $ai_page_ids = [];
	/**
	 * @var string|null
	 */
	public $process_id = null;

	/**
	 * @var ImportHelper
	 */
	protected $json;

	/**
	 * @var TemplateFactory
	 */
	protected $factory;

	/**
	 * @throws Exception
	 */
	public function __construct( $request_params ) {
		$this->dev_mode   = defined('TEMPLATELY_DEV') && TEMPLATELY_DEV;
		$this->origin     = $request_params['origin'];
		$this->prv_dir    = $request_params['prv_dir'];
		$this->dir_path   = $request_params['dir_path'];
		$this->manifest   = &$request_params['manifest'];
		$this->platform   = $this->manifest['platform'] ?? '';
		$this->session_id = $request_params['session_id'];


		$this->factory = new TemplateFactory( $this->platform );
		$this->json = Utils::get_json_helper( $this->platform );
		$this->json->session_id = $this->session_id;

		$this->ai_page_ids = $request_params['ai_page_ids'] ?? [];
		$this->process_id = $request_params['process_id'] ?? null;

		if ( empty( $this->platform ) ) {
			throw new Exception( __( 'Platform is not specified. Please try again after specifying the platform.', 'templately' ) );
		}
	}

	public function should_log(): bool {
		return true;
	}

	public function get_action(): string {
		return 'updateLog';
	}

	abstract public function get_name(): string;

	abstract public function get_label(): string;

	abstract public function should_run( $data, $imported_data = [] ): bool;

	abstract public function import( $data, $imported_data ): array;

	abstract public function log_message() : string;

	public function log( $progress = 0, $message = null, $action = null ) {
		if( ! $this->should_log() ) {
			return;
		}

		if( $message == null ) {
			$message = $this->log_message();
		}

		if( $action == null ) {
			$action = $this->get_action();
		}

		$this->sse_log( $this->get_name(), $message, min( $progress, 100 ), $action );
	}

	/**
	 * @throws Exception
	 */
	protected function throw($message, $code = 0) {
		if ($this->dev_mode) {
			error_log(print_r($message, 1));
		}
		throw new Exception($message);
	}

	protected function is_ai_content($old_template_id ): bool {
		if(empty($this->process_id) || empty($this->ai_page_ids) || !is_array($this->ai_page_ids)){
			return false;
		}
		// Get array of AI page IDs, filtering out any non-numeric values
		$ai_page_ids = array_reduce($this->ai_page_ids, 'array_merge', array());
		// $ai_page_ids = array_filter(
		// 	array_map('intval', explode(',', $this->ai_page_ids ?? ''))
		// );

		$is_ai_content = in_array($old_template_id, $ai_page_ids);

		return $is_ai_content;
	}
}