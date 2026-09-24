<?php

namespace Templately\Modules\FullSiteImport;

use Exception;
use Templately\Modules\FullSiteImport\Exception\SkippableErrorException;
use Templately\Utils\Helper;
use Templately\Modules\FullSiteImport\Runners\Attachments;
use Templately\Modules\FullSiteImport\Runners\BaseRunner;
use Templately\Modules\FullSiteImport\Runners\CustomCSS;
use Templately\Modules\FullSiteImport\Runners\Customizer;
use Templately\Modules\FullSiteImport\Runners\Dependencies;
use Templately\Modules\FullSiteImport\Runners\ElementorContent;
use Templately\Modules\FullSiteImport\Runners\ExtraContent;
use Templately\Modules\FullSiteImport\Runners\Finalizer;
use Templately\Modules\FullSiteImport\Runners\GutenbergContent;
use Templately\Modules\FullSiteImport\Runners\Loop;
use Templately\Modules\FullSiteImport\Runners\Templates;
use Templately\Modules\FullSiteImport\Runners\WPContent;

class Import {
	use Concerns\LogHelper;
	use Loop;


	/**
	 * @var array
	 */
	private $manifest;

	private $runners;
	private $request_params;
	private $session_id;

	private $imported_data = [];

	/**
	 * @throws Exception
	 */
	public function __construct( $args ) {
		$this->request_params = $args;
		$this->manifest = $args['manifest'];
		$this->session_id = $args['session_id'];

		$this->register_runners();
	}

	/**
	 * @throws Exception
	 */
	private function register_runners() {
		$this->runners = [
			// TODO: Site Settings Import Runner
			new Dependencies( $this->request_params ),
			new Attachments( $this->request_params ),
			new Customizer( $this->request_params ),
			new CustomCSS( $this->request_params ),
			new ExtraContent( $this->request_params ),
			new Templates( $this->request_params ),
			new GutenbergContent( $this->request_params ),
			new ElementorContent( $this->request_params ),
			new WPContent( $this->request_params ),
			new Finalizer( $this->request_params )
		];

		$this->runners = apply_filters( 'templately_import_runners', $this->runners, $this->request_params );
	}

	public function run( $callable = null ): array {
		$data = $this->request_params;

		/**
		 * Pre-flight: the pack's builder must be LOADED before any runner touches
		 * it. Runs here — not only inside the Dependencies runner — because that
		 * runner is skipped whenever the request carries no `theme`/`plugins`
		 * (`Dependencies::should_run()`), and a skipped dependency step used to
		 * mean the import sailed on to the Templates runner and died with a raw
		 * PHP fatal instead of a readable error:
		 *
		 *   Uncaught Error: Class "Elementor\Core\Base\Document" not found
		 *
		 * SKIPPED while the Dependencies runner still has plugins to install. That
		 * runner installs the builder and then `exit`s with a `continue` event
		 * precisely because a plugin activated in this request is not loaded until
		 * the next one — so on the first slice of a site that does not yet have the
		 * builder, "not loaded" is the expected state and not a failure. Refusing
		 * there made the dependency step unreachable and turned every
		 * install-the-builder import into a hard stop that told the user to install
		 * by hand the very plugin the importer was about to install for them.
		 *
		 * Cheap (two class_exists calls) and idempotent across resumed slices.
		 */
		if ( ! Dependencies::installation_pending( $data ) ) {
			Dependencies::verify_platform_available( (string) ( $data['manifest']['platform'] ?? '' ) );
		}

		// Get the cached imported_data
		// $this->imported_data = $data['imported_data'] ?? [];

		/**
		 * @var BaseRunner $runner
		 */
		$imported_data = $this->loop( $this->runners, function($id, $runner, $imported_data ) use($data) {
			$import = [];

			try{
				if ( $runner->should_run( $data, $imported_data ) ) {
					if (!$this->is_key_processed($runner->get_name(), 'runner_start')) {
						$runner->log( 0 );
						$this->mark_key_processed($runner->get_name(), 'runner_start');
					}
					$import        = $runner->import( $data, $imported_data );
					$imported_data = array_merge( $imported_data, $import );

					if( $runner->get_name() != 'finalize' ) {
						$runner->log( 100 );
					} else {
						$runner->log( 100, $runner->log_message() );
					}
				}
			}catch (Exception $e){
				Helper::log(
					sprintf(
						'%s (runner: %s, %s:%d)' . "\n%s",
						$e->getMessage(),
						$runner->get_name(),
						$e->getFile(),
						$e->getLine(),
						$e->getTraceAsString()
					),
					'import_runner_error'
				);

				// Swallow ONLY skippable errors (item skipped, import continues). Every
				// other error propagates so the SSE layer can surface it (034 FR-002):
				// Retryable → Retry affordance, NonRetirable → stop, Unknown/other → surfaced.
				if ( ! ( $e instanceof SkippableErrorException ) ) {
					throw $e;
				}
			}

			return $imported_data;
		}, null, true);

		return $imported_data;
	}

	public function get_runners() {
		return $this->runners;
	}
}
