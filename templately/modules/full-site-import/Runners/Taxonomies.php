<?php

namespace Templately\Modules\FullSiteImport\Runners;

use Templately\Modules\FullSiteImport\Runners\BaseRunner;

class Taxonomies extends BaseRunner {
	public function get_name(): string {
		return 'taxonomies';
	}

	public function get_label(): string {
		return __( 'Taxonomies', 'templately' );
	}

	public function should_run( $data, $imported_data = [] ): bool {
		// === DEPRECATED / UNUSED (kept intentionally) ===
		// Taxonomy import is NOT implemented and this runner is NOT registered in
		// Import::register_runners(). Force-decline so it can never silently import
		// nothing if it is ever wired into the pipeline by mistake (034 FR-009).
		return false;
	}

	public function log_message(): string {
		return __( 'Importing Taxonomies', 'templately' );
	}

	public function import( $data, $imported_data ): array {
		// === DEPRECATED / UNUSED (kept intentionally) ===
		// Not implemented — see should_run(); returns no imported data.
		return [];
	}
}