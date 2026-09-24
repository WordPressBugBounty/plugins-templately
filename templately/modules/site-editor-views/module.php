<?php
/**
 * Site Editor Views module (spec 054-site-editor-views).
 *
 * Makes imported content legible where it lives: records where each imported
 * item came from, and — on a host that supports contributed screen
 * configuration — surfaces that on the Site Editor's Pages screen as three
 * fields and two saved views.
 *
 * The module has TWO halves and they are gated differently on purpose:
 *
 * - **Recording** runs everywhere, down to the advertised WordPress floor. It
 *   has no host requirement at all.
 * - **Presenting** attaches only where `site-editor-view-config` resolves.
 *
 * That is why the gate is a SUB-FEATURE gate rather than `get_requirements()`.
 * A module-level requirement would stop the module booting below WP 7.1, which
 * would stop provenance being RECORDED there — manufacturing exactly the
 * backfill problem spec 054 rules out (FR-005). A site that upgrades later
 * finds its post-feature imports already legible, and that only works if the
 * recording half never depended on the host.
 *
 * Scope, and the reason it is narrow: v1 contributes to the PAGES screen only
 * (FR-009). The Templates and Parts screens list the host's own template
 * entities, and Templately theme-builder templates are a separate post type, so
 * fields contributed there could never render. The Patterns screen fails the
 * mirror test — catalog patterns are registered on demand, not stored as user
 * pattern posts. An earlier plan claimed otherwise; it was wrong.
 *
 * It DOES ship JavaScript, contrary to what this docblock claimed until
 * 2026-08-04. PHP decides which fields and views a screen offers; what each
 * field IS — its label, and how to read its value off a record — is defined on
 * the client through `wp.editor.registerEntityField()`. Contribute ids without
 * that and the views render and then filter nothing. See
 * `enqueue_field_definitions()` below.
 *
 * PHP 7.2 SYNTAX ONLY (Constitution XV).
 *
 * @package Templately
 */

namespace Templately\Modules\SiteEditorViews;

use Templately\Core\Module_Base;

defined( 'ABSPATH' ) || exit;

class Module extends Module_Base {

	/**
	 * @var Provenance
	 */
	protected $provenance;

	public function get_name(): string {
		return 'site-editor-views';
	}

	/**
	 * The presentation half's gate.
	 *
	 * NOT `get_requirements()` — see the class docblock. An unmet gate leaves
	 * the module booted and only detaches what it guards.
	 *
	 * @return array<string, string>
	 */
	public function get_capability_gates(): array {
		return [
			'screen-views' => 'site-editor-view-config',
		];
	}

	protected function init_hooks(): void {
		$this->provenance = new Provenance();

		// Recording — unconditional, every host.
		add_action( 'init', [ $this->provenance, 'register' ] );
		add_filter( 'templately_fsi_item_meta', [ $this->provenance, 'contribute' ], 10, 3 );
		// Single-template imports have no pipeline bag to contribute to; they announce
		// themselves through the producer-neutral action instead (spec 060 FR-002a).
		add_action( 'templately_blocks_imported', [ $this->provenance, 'stamp_single' ], 10, 2 );

		// Presenting — only where the host can. Nothing below is registered
		// otherwise, so there is no filter to misbehave, no notice, and no
		// dead UI (FR-010).
		if ( ! $this->has_gate( 'screen-views' ) ) {
			return;
		}

		$this->add_component( 'view-config', new ViewConfig( $this->provenance ) );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_field_definitions' ] );
	}

	/**
	 * Register the field DEFINITIONS on the client.
	 *
	 * The server-side configuration only references field ids; what each field
	 * IS — its label and how to read its value — is defined through the
	 * editor's client registrar. Contributing ids without this produces views
	 * that render and then filter nothing, which is what an E2E run on 7.1
	 * caught after the PHP-only implementation looked complete.
	 *
	 * Enqueued on the Site Editor screen only. It has no job anywhere else, and
	 * the SPA bundle never loads there.
	 *
	 * @param string $hook The current admin page.
	 * @return void
	 */
	public function enqueue_field_definitions( $hook ): void {
		if ( 'site-editor.php' !== $hook ) {
			return;
		}

		$handle = 'templately-site-editor-views';
		$asset  = TEMPLATELY_PATH . 'assets/js/site-editor-views.asset.php';

		// These two are declared by hand and MERGED with whatever the build
		// extracted, never replaced by it. The script reads `wp.editor` and
		// `wp.i18n` off the global rather than importing the packages, so
		// dependency extraction correctly finds nothing to extract — and an
		// empty dependency list means WordPress is free to run this before
		// `wp-editor` exists, at which point the registrar is undefined and
		// nothing registers. That failure is silent: the views still render,
		// and then filter nothing. It cost an E2E run to find once already.
		$deps    = [ 'wp-editor', 'wp-i18n' ];
		$version = TEMPLATELY_VERSION;

		if ( file_exists( $asset ) ) {
			$meta = include $asset;

			if ( isset( $meta['dependencies'] ) && is_array( $meta['dependencies'] ) ) {
				$deps = array_values( array_unique( array_merge( $deps, $meta['dependencies'] ) ) );
			}

			$version = isset( $meta['version'] ) ? $meta['version'] : $version;
		}

		wp_enqueue_script(
			$handle,
			TEMPLATELY_URL . 'assets/js/site-editor-views.js',
			$deps,
			$version,
			true
		);
	}
}
