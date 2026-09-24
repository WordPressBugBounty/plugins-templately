<?php
/**
 * In-editor AI chat module (spec 041-ai-editor).
 *
 * Owns the "Edit with Templately" conversational panel that mounts inside the
 * Elementor and Gutenberg editors, the REST proxy that relays turns to the
 * Templately cloud, and the standalone host page that wraps a post's native
 * editor.
 *
 * Relocated here from the pre-modular tree:
 *   includes/API/AIEditor.php        -> REST/AIEditor.php
 *   includes/Admin/EditorPage.php    -> EditorPage.php
 *   react-src/ai-editor/             -> assets/js/
 * `views/ai-editor-page.php` deliberately stays at the plugin root: every
 * module renders through `Helper::views()`, which resolves against
 * `TEMPLATELY_PATH . 'views/'` only (see modules/settings/Settings.php).
 *
 * ENQUEUE OWNERSHIP. The two `js/ai-editor.js` enqueues used to live inside
 * Platform/Elementor.php and Platform/Gutenberg.php, which now belong to
 * elementor-integration and gutenberg-integration. Keeping them there would
 * make two unrelated modules responsible for this module's bundle. Both are
 * plain action hooks (`elementor/editor/after_enqueue_scripts`,
 * `enqueue_block_editor_assets`), so this module subscribes to them directly
 * and the platform modules no longer mention the AI editor at all.
 *
 * ENTRY KEY. `webpack.config.partial.js` keeps the unprefixed `ai-editor` key
 * rather than `modules/ai-editor`. A `modules/`-prefixed key folds the code
 * into the SPA bundle graph (Constitution XIII) and stops emitting a real
 * file — but this bundle is enqueued BY PATH as `js/ai-editor.js` on editor
 * screens, and must not load on the SPA admin page. Same exception as
 * `elementor` and `gutenberg`.
 *
 * @package Templately
 */

namespace Templately\Modules\AiEditor;

use Templately\Core\Module_Base;
use Templately\Modules\AiEditor\REST\AIEditor;

class Module extends Module_Base {

	public function get_name(): string {
		return 'ai-editor';
	}

	/**
	 * Held back from the 3.8.0 release (2026-09-23) — see Module_Base::deferred_module_enabled().
	 * Delete this override to release it.
	 */
	public function is_active(): bool {
		return Module_Base::deferred_module_enabled( $this->get_name() );
	}

	/**
	 * No declared dependencies — deliberately.
	 *
	 * The only cross-module touchpoints are late and guarded:
	 *   - `resolve_site_template()` reads `templately()->theme_builder` (set by
	 *     theme-builder's own init_hooks) behind an `empty()` check and returns
	 *     null when absent, so `@header`/`@footer` degrade instead of fatalling.
	 *   - the browser calls `POST /templately/v1/insert` (single-import) over
	 *     HTTP, which is a route-availability concern, not a boot-order one.
	 *
	 * Declaring either would make Modules_Manager skip this module outright if
	 * that module failed to boot — strictly worse than the graceful degradation
	 * above. Neither reference is a `use Templately\Modules\…` import, so
	 * `scripts/build/check-module-dependencies.js` does not flag them and no
	 * baseline entry is needed.
	 */
	public function get_dependencies(): array {
		return [];
	}

	protected function init_hooks(): void {
		// Self-registered here rather than from includes/Plugin.php: during
		// plugins_loaded, Plugin runs BEFORE Modules_Manager registers this
		// module's autoloader, so an eager reference there fatals on
		// class-not-found. See docs/CREATING-A-MODULE.md -> boot-ordering rule.
		EditorPage::get_instance();

		add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_elementor' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_gutenberg' ] );
	}

	public function register_rest_routes(): void {
		AIEditor::get_instance()->register_routes();
	}

	/**
	 * Elementor editor. Per-post capability is enforced by the REST permission
	 * callback on every turn; `edit_posts` here only decides whether the panel
	 * is offered at all, matching the check this replaced.
	 *
	 * @return void
	 */
	public function enqueue_elementor(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		templately()->assets->enqueue( 'templately-ai-editor', 'js/ai-editor.js' );
	}

	/**
	 * Block editor. `post-new.php` has no post ID yet, so fall back to the
	 * generic capability there — same branch as the check this replaced.
	 *
	 * @return void
	 */
	public function enqueue_gutenberg(): void {
		$editor_post_id = get_the_ID();
		$can_use        = $editor_post_id
			? current_user_can( 'edit_post', $editor_post_id )
			: current_user_can( 'edit_posts' );

		if ( ! $can_use ) {
			return;
		}
		templately()->assets->enqueue( 'templately-ai-editor', 'js/ai-editor.js' );
	}
}
