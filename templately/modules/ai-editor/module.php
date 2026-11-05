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
use Templately\Utils\Options;

class Module extends Module_Base {

	public function get_name(): string {
		return 'ai-editor';
	}

	/**
	 * On for every site for the alpha test (2026-10-05). Set to false to hold the editor
	 * back again; it then boots only where deferred modules are switched on
	 * (see Module_Base::deferred_module_enabled()).
	 */
	const ENABLED = true;

	/**
	 * Held back from the 3.8.0 release (2026-09-23); released for the alpha by ENABLED.
	 */
	public function is_active(): bool {
		return self::ENABLED || Module_Base::deferred_module_enabled( $this->get_name() );
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
		add_filter( 'wp_client_side_media_processing_enabled', [ $this, 'no_isolation_when_hosted' ] );
		add_filter( 'elementor/editor/use_document_isolation_policy', [ $this, 'no_isolation_when_hosted' ] );
		add_filter( 'elementor/document/urls/preview', [ $this, 'keep_host_flag_in_preview' ] );
		add_filter( 'templately_admin_localized_data', [ $this, 'localize_offer_dismissed' ] );
	}

	/**
	 * Tell the library page whether this WP user closed the free-credit alert.
	 *
	 * Localized from here rather than from core's Admin.php so the flag exists
	 * only while this module runs: the alert renders only when the key is
	 * present and false, so a held-back AI editor shows no offer for it.
	 *
	 * @param array $data the `window.templately` payload
	 * @return array
	 */
	public function localize_offer_dismissed( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$data['ai_editor_offer_dismissed'] = (bool) Options::get_instance()->get( AIEditor::OFFER_DISMISSED_KEY, false, get_current_user_id() );
		// Keys the alert's local copy of the dismissal per WP user, so a failed or
		// slow dismiss request cannot bring it back on this browser.
		$data['ai_editor_offer_user_id'] = get_current_user_id();

		return $data;
	}

	/**
	 * Let the "Edit with Templately AI" page host the editor on WordPress 7.1+.
	 *
	 * WordPress 7.1 sends `Document-Isolation-Policy: isolate-and-credentialless` on
	 * editor screens in Chromium 137+ (client-side media processing). The isolated editor
	 * renders blank inside the host page's iframe, so every HTTPS site showed "Your site
	 * blocks embedded editing" (2026-10-05). Core skips the isolation for page builders
	 * for the same reason; this does so only for the hosted request (`templately_host=1`),
	 * where uploads fall back to server-side image processing. The editor opened directly
	 * keeps it. Approved by the owner, 2026-10-05.
	 *
	 * Elementor 4.1+ sends the same header itself on its editor and its preview iframe
	 * (they must match, or the editor loses its preview), through its own filter.
	 *
	 * @param bool $enabled Whether WordPress would process media in the browser.
	 * @return bool
	 */
	public function no_isolation_when_hosted( $enabled ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
		if ( isset( $_GET['templately_host'] ) && '1' === $_GET['templately_host'] ) {
			return false;
		}

		return $enabled;
	}

	/**
	 * Carry `templately_host=1` onto the Elementor preview iframe of a hosted editor, so
	 * the preview drops the isolation too and stays reachable from the editor.
	 *
	 * @param string $url The preview URL.
	 * @return string
	 */
	public function keep_host_flag_in_preview( $url ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
		if ( isset( $_GET['templately_host'] ) && '1' === $_GET['templately_host'] ) {
			return add_query_arg( 'templately_host', 1, $url );
		}

		return $url;
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
