<?php

namespace Templately\Modules\FullSiteImport\Runners;

use Exception;
use Templately\Modules\FullSiteImport\Utils\Form;
use Templately\Modules\FullSiteImport\Runners\BaseRunner;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Utils\Helper;

class Customizer extends BaseRunner {

	public function get_name(): string {
		return 'customizer';
	}

	public function get_label(): string {
		return __( 'Customizer', 'templately' );
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
		return ! empty( $data['title'] ) || !empty( $data['slogan'] );
	}

	public function import( $data, $imported_data ): array {
		$params = $data;
		$customizer = [];

		$this->log( 0 );

		if( ! empty( $params['title'] ) ) {
			// set_theme_mod( 'blogname', $params['title'] );
			if ( $this->update_site_option( 'blogname', $params['title'], __( 'site title', 'templately' ) ) ) {
				$customizer[] = 'title';
			}
		}

		// update Tagline
		if( ! empty( $params['slogan'] ) ) {
			// set_theme_mod( 'blogdescription', $params['slogan'] );
			if ( $this->update_site_option( 'blogdescription', $params['slogan'], __( 'tagline', 'templately' ) ) ) {
				$customizer[] = 'slogan';
			}
		}

		return  [ 'customizer' => $customizer ];
	}

	/**
	 * Write one customizer option, contained.
	 *
	 * `update_option()` runs third-party hook code, and the two options this runner writes
	 * are the ones builders listen on: Elementor hooks `update_option_blogname` /
	 * `update_option_blogdescription` and syncs the value into the ACTIVE KIT. On a site
	 * whose kit post is gone — a bad restore, a deleted kit, a half-finished migration —
	 * that sync throws `Exception( 'Invalid post.' )` from Elementor's page settings
	 * manager. Uncontained, that exception left the runner, aborted the whole import as a
	 * NON-retryable "Oops! / Invalid post." four frames from its real cause, and gave the
	 * user no way forward — over a site title.
	 *
	 * Contained per field: the failure is logged, an `eventLog` line names the field, and
	 * the import continues (the other field, and every later runner, still run). \Throwable
	 * rather than Exception because the same hook chain can raise a PHP \Error from an
	 * outdated add-on — the same reason the Templates runner catches one.
	 *
	 * Returning false means "do not report this field as applied" — deliberately
	 * conservative, since where the throw came from decides whether the value landed:
	 * `update_option()` fires its hooks AFTER the DB write, so an Elementor kit-sync
	 * failure leaves the option itself written, while a `pre_update_option_*` filter
	 * throwing means nothing was. The user-facing line is worded for both. The revert
	 * backup is taken before either, so revert still restores the old value.
	 *
	 * @return bool True when the option was written without a hook blowing up.
	 */
	private function update_site_option( $key, $value, $label ): bool {
		try {
			Utils::update_option( $key, $value );
			return true;
		} catch ( \Throwable $e ) {
			Helper::log(
				sprintf( 'Customizer option "%s" failed: %s', $key, $e->getMessage() ),
				'fsi',
				'error'
			);

			$this->log(
				0,
				sprintf(
					/* translators: %s: the customizer field name, e.g. "site title" */
					__( 'Could not fully apply the %s — a plugin hooked to that change failed. The rest of the pack was imported.', 'templately' ),
					$label
				),
				'eventLog'
			);

			return false;
		}
	}

}