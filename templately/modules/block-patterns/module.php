<?php
/**
 * Block Patterns module (spec 051-block-patterns).
 *
 * Exposes the Templately Gutenberg catalog as native WordPress block patterns:
 * plan-keyed remote sync into a local cache (transient list + inert content
 * files), metadata-only registration on guarded `init` with WP 6.5 filePath
 * lazy content, start-page/post pattern keys for the native new-page chooser,
 * a save-time media localizer with persistent source-URL dedup, and a
 * cache-fed dashboard widget. v1 is connected-sites-only and WP >= 6.5 only
 * (spec 051 Clarifications, 2026-07-28). Design decisions: specs/051-block-
 * patterns/research.md (D1-D9).
 *
 * @package Templately
 */

namespace Templately\Modules\BlockPatterns;

use Templately\Core\Capabilities;
use Templately\Core\Module_Base;
use Templately\Modules\BlockPatterns\Cleanup\PatternCacheTask;
use Templately\Modules\BlockPatterns\REST\Content as ContentEndpoint;
use Templately\Modules\BlockPatterns\REST\Search as SearchEndpoint;
use Templately\Modules\BlockPatterns\REST\Sync as SyncEndpoint;

class Module extends Module_Base {

	public function get_name(): string {
		return 'block-patterns';
	}

	/**
	 * WP >= 6.5 only: filePath lazy pattern content (the whole registration
	 * design) does not exist below 6.5, and v1 ships no inline fallback
	 * (research D8 / spec FR-006).
	 *
	 * Since spec 053 the check is the `pattern-file-path` capability. Its
	 * declaration (Capability_Seed) still routes the version through the
	 * legacy `templately_block_patterns_wp_version` filter, so existing tests
	 * and third-party code keep working; the documented override going
	 * forward is `templately_capability_pattern-file-path`.
	 */
	public function is_active(): bool {
		return Capabilities::get_instance()->has( 'pattern-file-path' );
	}

	public function get_dependencies(): array {
		// full-site-import: Utils::import_and_replace_attachments (MediaLocalizer);
		// gutenberg-integration: registers the gutenberg json helper that util resolves
		// through the templately_fsi_json_helper seam;
		// utilities: the guarded remover this module's cache-clear deletes through.
		return [ 'full-site-import', 'gutenberg-integration', 'utilities' ];
	}

	protected function init_hooks(): void {
		// Manual-only contribution to the shared cleanup service (spec 052): a
		// "clear the pattern library" action for the Utilities tab. Never
		// scheduled — every cleared pattern is re-fetched from the cloud.
		PatternCacheTask::register();

		// REGISTER ON `init`, LIKE CORE DOES — and never on any later action.
		//
		// This used to run on `rest_api_init` to keep the catalog out of the
		// editor's bootstrap payload. It achieved the exact opposite. WordPress
		// keeps a SECOND copy of every pattern registered outside `init`:
		//
		//   // class-wp-block-patterns-registry.php
		//   if ( current_action() && 'init' !== current_action() ) {
		//       $this->registered_patterns_outside_init[ $pattern_name ] = $pattern;
		//   }
		//
		// and `wp-admin/edit-form-blocks.php` inlines that bucket wholesale into
		// the page as `__experimentalAdditionalBlockPatterns`. Core keeps it only
		// to detect DEPRECATED registrations from `admin_init`/`current_screen` —
		// its own comment says so — and we were feeding it as our main path.
		// Measured on a connected site: 100 of 100 of our patterns in that bucket,
		// 87,630 bytes inlined into every single editor page load.
		//
		// Registered on `init` we are in the ordinary registry only, which nothing
		// inlines, and core-data's `getBlockPatterns` resolver fetches
		// `/wp/v2/block-patterns/patterns` when the editor actually wants patterns.
		//
		// The original fear — that `init` registration forces content resolution —
		// belonged to the eager `filePath` era, when one pattern averaged ~50KB and
		// the whole catalog inlined megabytes. In lazy mode a pattern's content IS
		// the ~865-byte placeholder, passed inline, so there is no file to resolve
		// and nothing to defer.
		//
		// PRIORITY 20 IS LOAD-BEARING. Core registers its own pattern categories on
		// `init` at the default 10 (`_register_core_block_patterns_and_categories`,
		// `_register_theme_block_patterns`). `PatternRegistrar::core_category_for()`
		// asks the live registry whether a core equivalent exists, so running before
		// core would find none and file every design into a parallel `templately-*`
		// bucket instead of core's "Call to action", "Header" and friends.
		add_action( 'init', function () {
			PatternRegistrar::get_instance()->register_all();
		}, 20 );

		// Cron: self-chaining sync events (research D5).
		add_action( PatternSync::EVENT_SYNC_LIST, [ PatternSync::get_instance(), 'sync_list' ] );
		add_action( PatternSync::EVENT_SYNC_CONTENT, [ PatternSync::get_instance(), 'sync_content_batch' ] );

		// Plan/connection boundary invalidation: the stored account lives in
		// `_templately_user` user meta (written via update_user_option on login/
		// logout/plan refresh) — deleting the plan-keyed caches on any write to it
		// covers connect, disconnect and plan change (plan.md cross-cutting rules).
		$invalidate = function ( $meta_id, $object_id, $meta_key ) {
			PatternSync::get_instance()->maybe_invalidate_for_meta( (string) $meta_key );
		};
		add_action( 'updated_user_meta', $invalidate, 10, 3 );
		add_action( 'added_user_meta', $invalidate, 10, 3 );
		add_action( 'deleted_user_meta', $invalidate, 10, 3 );

		// Save-time media localization (research D6).
		add_action( 'wp_after_insert_post', [ MediaLocalizer::get_instance(), 'maybe_localize' ], 10, 2 );
		add_action( MediaLocalizer::EVENT_LOCALIZE_REST, [ MediaLocalizer::get_instance(), 'localize_deferred' ] );

		// Media is imported when a pattern is INSERTED, so a discarded draft would
		// otherwise leave its images orphaned in the library.
		add_action( 'before_delete_post', [ MediaLocalizer::get_instance(), 'cleanup_for_post' ] );

		// Dashboard widget (research D9: server-rendered, cache-only).
		add_action( 'wp_dashboard_setup', [ DashboardWidget::get_instance(), 'register_widget' ] );

		// First sync: schedule (never fetch inline) when no list has ever synced.
		add_action( 'admin_init', [ PatternSync::get_instance(), 'ensure_scheduled' ] );

		// The lazy-pattern placeholder block. Registered server-side so the block
		// type exists for parsing/validation; its editor UI ships in this module's
		// JS, which compiles into the `templately` bundle via the boot manifest
		// (react-src/generated/moduleManifest.ts) and rides that bundle's
		// unconditional enqueue in Admin::enqueue_scripts() onto the editor screen.
		// That is the same reach the retired enqueue_module_assets() glob had.
		add_action( 'init', function () {
			if ( ! PatternRegistrar::lazy_mode() ) {
				return;
			}
			if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'templately/lazy-pattern' ) ) {
				register_block_type( 'templately/lazy-pattern', [
					'api_version'     => 3,
					'attributes'      => [
						'patternId'  => [ 'type' => 'number', 'default' => 0 ],
						'previewUrl' => [ 'type' => 'string', 'default' => '' ],
						'title'      => [ 'type' => 'string', 'default' => '' ],
						'kind'       => [ 'type' => 'string', 'default' => '' ],
					],
					// Never renders on the front end: on the canvas it replaces
					// itself with the real blocks, so it cannot reach saved content.
					'render_callback' => '__return_empty_string',
				] );
			}
		}, 5 );

		// The placeholder renders inside the editor CANVAS IFRAME, which the
		// module's admin stylesheet never reaches — enqueue_block_assets is the
		// hook WordPress mirrors into that iframe, so the loading/error state
		// keeps its Templately styling instead of rendering unstyled.
		add_action( 'enqueue_block_assets', function () {
			if ( ! is_admin() || ! PatternRegistrar::lazy_mode() ) {
				return;
			}

			// Emitted by this module's OWN stylesheet entry, not by the retired module-CSS glob
			// that used to write assets/css/modules/style-block-patterns.css. Every module's
			// JS-imported CSS now lands in style-templately.css, which is enqueued on the admin
			// page and never reaches the canvas iframe — hence the separate entry. The
			// `style-` prefix is @wordpress/scripts': a source file named `style.scss` emits
			// `style-{entry}.css`. See webpack.config.partial.js.
			$relative = 'assets/css/style-block-patterns.css';
			if ( ! file_exists( TEMPLATELY_PATH . $relative ) ) {
				return;
			}

			wp_enqueue_style(
				'templately-block-patterns',
				TEMPLATELY_URL . $relative,
				[],
				(string) filemtime( TEMPLATELY_PATH . $relative )
			);
		} );
	}

	public function register_rest_routes(): void {
		ContentEndpoint::get_instance()->register_routes();
		SyncEndpoint::get_instance()->register_routes();
		SearchEndpoint::get_instance()->register_routes();
	}
}
