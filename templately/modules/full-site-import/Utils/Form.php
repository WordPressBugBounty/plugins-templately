<?php

namespace Templately\Modules\FullSiteImport\Utils;

use Exception;
use FluentForm\App\Services\Transfer\TransferService;
use FluentForm\Framework\Request\File;
use Templately\Utils\Helper;

class Form {
	protected $plugin;
	protected $file;
	protected $settings = [];

	public function __construct( $plugin, $file, $settings = [] ) {
		$this->plugin   = $plugin;
		$this->file     = $file;
		$this->settings = $settings;
	}

	/**
	 * @throws Exception
	 */
	public function run() {
		if ( ! file_exists( $this->file ) ) {
			throw new Exception( __( 'Form JSON does not exists.', 'templately' ) );
		}

		$data = $this->import();

		if ( ! $data ) {
			throw new Exception( __( 'Cannot be imported.', 'templately' ) );
		}

		return $data;
	}

	public function import() {
		try {
			$results = null;
			switch ( $this->plugin ) {
				// `finfo` (the PHP `fileinfo` extension) is required: FluentForm's
				// File::__construct() resolves the MIME type via `new finfo()`, which
				// fatals if the extension is missing. Guard it so we skip the form
				// import instead of crashing the whole site import.
				case 'fluent-forms' && is_plugin_active( 'fluentform/fluentform.php' ) && class_exists( File::class ) && class_exists( TransferService::class ) && class_exists( 'finfo' ):
					$fileObject = new File( $this->file, '' );
					$importer   = new TransferService();
					$inserted   = $importer->importForms( $fileObject );
					$results    = key( $inserted['inserted_forms'] );
					break;
				default:
					break;
			}

			return $results;

		} catch ( \Throwable $e ) {
			// \Throwable, not \Exception: a fatal \Error raised inside the third-party
			// form importer (a missing class or constant) is NOT an Exception, so the
			// old catch could not contain it and one broken form aborted the entire
			// site import.
			//
			// This swallows the failure by design — a form that cannot be imported must
			// not stop the site import — but silence made every such failure invisible,
			// so it is logged before being dropped.
			Helper::log(
				sprintf( 'Form import failed (%s): %s', $this->plugin, $e->getMessage() ),
				'fsi',
				'error'
			);

			return null;
		}
	}
}