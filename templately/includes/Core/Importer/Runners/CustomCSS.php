<?php

namespace Templately\Core\Importer\Runners;

use Templately\Core\Importer\Runners\BaseRunner;
use Templately\Core\Importer\Utils\Utils;

/**
 * Imports the pack's site-wide custom CSS (`custom.css` at the pack root) into
 * the `templately_custom_css` option, which Settings::add_custom_css() prints
 * inside a <style> tag on wp_head.
 *
 * The value is written through Utils::update_option(), so the previous value is
 * backed up to `__templately_templately_custom_css` and import_revert() restores
 * it — the same backup/revert path every other imported option uses.
 */
class CustomCSS extends BaseRunner {

	const OPTION_KEY = 'templately_custom_css';
	const FILE_NAME  = 'custom.css';

	public function get_name(): string {
		return 'custom-css';
	}

	public function get_label(): string {
		return __( 'Custom CSS', 'templately' );
	}

	public function should_log(): bool {
		return true;
	}

	public function get_action(): string {
		return 'eventLog';
	}

	public function log_message(): string {
		return __( 'Importing custom CSS.', 'templately' );
	}

	public function should_run( $data, $imported_data = [] ): bool {
		$file = $this->dir_path . self::FILE_NAME;
		return is_readable( $file ) && filesize( $file ) > 0;
	}

	public function import( $data, $imported_data ): array {
		$this->log( 0 );

		$file = $this->dir_path . self::FILE_NAME;
		$css  = file_get_contents( $file );

		if ( false === $css || '' === trim( $css ) ) {
			return [];
		}

		// Backup the current value then write the pack CSS. The backup lands in
		// `__templately_templately_custom_css`; import_revert() restores it.
		Utils::update_option( self::OPTION_KEY, $css );

		return [ 'custom_css' => true ];
	}
}
