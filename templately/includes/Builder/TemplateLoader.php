<?php

namespace Templately\Builder;

use Elementor\Plugin;
use Templately\Builder\Managers\LocationManager;
use Templately\Builder\Managers\ThemeCompatibility;
use Templately\Builder\Types\BaseTemplate;
use Templately\Utils\Views;
use ElementorPro\Modules\ThemeBuilder\Module;

class TemplateLoader {
	/**
	 * @var Views
	 */
	private $views;

	/**
	 * Stack of booleans, one per in-flight document render, recording whether
	 * that call switched the main query to a preview product. A stack (not a
	 * single flag) so nested/re-entrant renders — e.g. a `product_single`
	 * template embedding another Elementor document via a Template widget —
	 * restore correctly. Static ( not per-instance ) because the hooks that
	 * drive it are registered unconditionally ( register_preview_product_hooks() ),
	 * independent of whether a per-request TemplateLoader instance exists.
	 *
	 * @var bool[]
	 */
	private static $preview_query_switch_stack = [];

	/**
	 * Registers the WooCommerce preview-product query switch, unconditionally
	 * ( admin and front end alike — unlike the rest of this class, which is only
	 * instantiated on the front-end `wp` hook ). Called once from ThemeBuilder's
	 * constructor.
	 *
	 * Three independent render pathways need their own switch, because none of
	 * them share a common choke point:
	 * - Real front end / live preview iframe: Document::get_content() ->
	 *   Frontend::get_builder_content(). This is a normal front-end request, so
	 *   it WOULD also be reachable from a `wp`-gated instance, but is registered
	 *   here too for symmetry with the pathways below and so both share one stack.
	 * - Editor admin page bootstrap ( post.php?action=elementor, which never
	 *   fires the `wp` action ): Document::get_config() bakes each widget's
	 *   server-rendered `render()` output into `ElementorConfig.initial_document`
	 *   as `htmlCache` via get_elements_raw_data( null, true ), fired right after
	 *   `before_get_config`. This does NOT go through Frontend::get_builder_content()
	 *   at all, so it needs its own switch/restore pair.
	 * - Adding a widget to the LIVE canvas ( no save/reload in between ): a PHP-only
	 *   widget with no JS `content_template()` — every EA Woo widget, and most of
	 *   ours — is rendered via the `render_widget` ajax action
	 *   ( Widgets_Manager::ajax_render_widget() -> Document::render_element() ),
	 *   which goes through NEITHER get_content() NOR get_config(). Confirmed by
	 *   direct testing: dropping one of these widgets onto a brand-new, unsaved
	 *   product_single template shows the widget's placeholder until the whole
	 *   document is reloaded ( which bakes via get_config() and so IS covered ) —
	 *   reloading immediately after masks the gap, which is why it wasn't caught
	 *   sooner.
	 *
	 *   Two other approaches were tried and confirmed NOT to work, by direct
	 *   testing with temporary logging (kept here so the next person doesn't
	 *   re-walk the same dead ends):
	 *   - Switching the query earlier ( e.g. on `wp_ajax_elementor_ajax` ), on the
	 *     theory that `ajax_render_widget()`'s own `query_posts( [ 'p' =>
	 *     $editor_post_id, 'post_type' => 'any' ] )` (widgets.php) would silently
	 *     undo it. Red herring: `query_posts()` only replaces `$wp_query` — it
	 *     does NOT touch `global $post` at all ( that only happens via
	 *     `the_post()` / `setup_postdata()`, neither of which `ajax_render_widget()`
	 *     calls on its query ) — confirmed via a `the_posts` filter showing the
	 *     query itself resolving to the right product while `$GLOBALS['post']`
	 *     stayed on the templately_library document throughout.
	 *   - Rewriting that same `query_posts()` query via `pre_get_posts`. The query
	 *     rewrite itself worked ( confirmed the SQL fetched the preview product
	 *     correctly ) but was moot for the same reason: since `query_posts()`
	 *     never touches `global $post`, redirecting it changes nothing observable.
	 *
	 *   The actual cause: `Ajax\Module::handle_ajax_request()` calls
	 *   `Plugin::$instance->db->switch_to_post( $editor_post_id )` — which DOES
	 *   directly set `$GLOBALS['post']` + `setup_postdata()` — *before* dispatching
	 *   to any registered action (including `render_widget`), and nothing after
	 *   that point ever touches `global $post` again. So the fix has to override
	 *   `global $post` a second time, strictly *after* that call. `elementor/ajax/
	 *   register_actions` fires right after it and before the action dispatch loop
	 *   — see maybe_switch_global_post_for_ajax_render(). No restore needed: this
	 *   is a one-shot admin-ajax.php process that exits right after responding.
	 */
	public static function register_preview_product_hooks() {
		add_action( 'elementor/frontend/before_get_builder_content', [ self::class, 'maybe_switch_to_preview_product_query' ] );
		add_filter( 'elementor/frontend/the_content', [ self::class, 'maybe_restore_preview_product_query' ] );
		add_action( 'elementor/document/before_get_config', [ self::class, 'maybe_switch_to_preview_product_query' ] );
		add_filter( 'elementor/document/config', [ self::class, 'maybe_restore_preview_product_query' ] );

		add_action( 'elementor/ajax/register_actions', [ self::class, 'maybe_switch_global_post_for_ajax_render' ] );
	}

	public function __construct( $builder, $views ) {
		$this->views = $views;

		// new ThemeCompatibility();

		add_action( 'get_header', [ $this, 'get_header' ], 0 );
		add_action( 'get_footer', [ $this, 'get_footer' ], 0 );
		add_action( 'elementor/document/wrapper_attributes', [ $this, 'wrapper_attributes' ], 10, 2 );
		add_action( 'template_redirect', array( $this, 'set_global_product' ) );

		add_action( 'templately_builder_header_after', [ self::class, 'print_style_tags' ], 0 );
		add_action( 'templately_builder_footer_before', [ self::class, 'print_style_tags' ], 0 );
		/**
		 * Only for Development Mode.
		 */
		if ( defined( 'TEMPLATELY_DEV_VIEWS' ) && TEMPLATELY_DEV_VIEWS ) {
			add_action( 'templately_builder_header_before', [ $this, 'header_helper' ], 0 );
			add_action( 'templately_builder_footer_before', [ $this, 'footer_helper' ], 0 );
		}
	}

	public function header_helper() {
		echo '<small>Header</small>';
	}

	public function footer_helper() {
		echo '<small>Footer</small>';
	}

	public function get_header() {
		if(!self::is_header_footer('header')){
			return;
		}
		$this->views->get_header();

		$templates   = [];
		$templates[] = 'header.php';

		remove_all_actions( 'wp_head' );

		ob_start();
		locate_template( $templates, true );
		ob_get_clean();
	}

	public function get_footer( $name ) {
		if(!self::is_header_footer('footer')){
			return;
		}
		$this->views->get_footer();

		$templates = [];
		$name = (string) $name;
		if ( '' !== $name ) {
			$templates[] = "footer-{$name}.php";
		}

		$templates[] = 'footer.php';

		// remove_all_actions( 'wp_footer' );

		ob_start();
		locate_template( $templates, true );
		ob_get_clean();
	}

	public static function is_header_footer($type = null){
		if(class_exists( 'Elementor\Plugin' )){
			$pid       = get_the_ID();
			$post_type = get_post_type($pid);
			$document  = Plugin::$instance->documents->get( $pid );

			if(
				$post_type === 'elementor_library' &&
				(
					$document->get_type() === 'header' ||
					$document->get_type() === 'footer')
				){
				return false;
			}
		}

		$header = templately()->theme_builder::$conditions_manager->get_templates_by_location( 'header' );
		$footer = templately()->theme_builder::$conditions_manager->get_templates_by_location( 'footer' );

		if($type === 'header'){
			return $header;
		}
		else if($type === 'footer'){
			return $footer;
		}

		if(!empty($header) || !empty($footer)){
			return true;
		}
		return false;
	}

	public function wrapper_attributes( $attributes, $document ) {
		$post_type = get_post_type($document->get_main_id());

		if( $post_type === 'templately_library' ){
			$template                                = templately()->theme_builder->get_template( $document->get_main_id() );
			if($template){
				$attributes['data-elementor-type']       = 'templately-' . $template->get_type();
				$attributes['data-elementor-id']         = $template->get_main_id();
				$attributes['data-elementor-post-type']  = 'templately_library';
				$attributes['data-elementor-title']      = $template->get_title();
				$attributes['class']                    .= ' ' . implode( ' ', get_post_class() );
			}
		}

		return $attributes;
	}

	public function set_global_product(){
		global $product;

		if ( ! function_exists( 'wc_setup_product_data' ) ) {
			return;
		}

		// A real product is already in context (e.g. a live single-product page) — never override it.
		if ( $product instanceof \WC_Product ) {
			return;
		}

		$document_id = get_the_ID();
		if ( ! $document_id ) {
			return;
		}

		// The current post is itself a product — set it up (prior behaviour, kept explicit).
		if ( get_post_type( $document_id ) === 'product' ) {
			wc_setup_product_data( $document_id );
			return;
		}

		// Otherwise only fabricate a preview product for a WooCommerce single-product
		// Theme Builder template being rendered in the editor / preview. This mirrors what
		// Elementor Pro's Single Product document does: point the global $product at a real
		// product so any WooCommerce widget — ours and third-party (e.g. EA Woo Product Tabs) —
		// renders real data in the editor instead of appearing empty. The live front end is
		// unaffected (there a real product is always in context, handled above).
		//
		// This only sets `global $product`; it does NOT cover widgets/plugins that re-derive
		// the product from `global $post` (e.g. via `wc_get_product( false )`'s fallback, which
		// checks `get_post_type( $post->ID )`) instead of trusting a pre-set `$product`. That
		// gap is closed separately by maybe_switch_to_preview_product_query() below.
		if ( ! self::is_woo_single_preview( $document_id ) ) {
			return;
		}

		$preview_product_id = self::get_preview_product_id();
		if ( $preview_product_id ) {
			wc_setup_product_data( $preview_product_id );
		}
	}

	/**
	 * Swap the main query ( and `global $post` ) to the preview product for the
	 * duration of a `product_single` Theme Builder document's content render.
	 *
	 * Mirrors Elementor Pro's `Theme_Document::get_content()` / `Preview_Manager`
	 * ( `switch_to_preview_query()` + `DB::switch_to_query( $query_vars, true )` ),
	 * but built only on Elementor core APIs so it works without Elementor Pro.
	 * This is what makes widgets that re-derive the product from `global $post`
	 * ( e.g. EA's Woo Product Description, or `wc_get_product( false )`'s own
	 * fallback ) render real content instead of a placeholder — setting
	 * `global $product` alone ( set_global_product() above ) isn't enough for them.
	 *
	 * Hooked to both `elementor/frontend/before_get_builder_content` (real front end /
	 * live preview iframe) and `elementor/document/before_get_config` (editor admin
	 * page bootstrap), paired respectively with maybe_restore_preview_product_query()
	 * on `elementor/frontend/the_content` and `elementor/document/config`.
	 * `DB::switch_to_query()` / `restore_current_query()` are themselves stack-based,
	 * but the hook only gives us the document on the "before" call, so a small
	 * boolean stack here is what lets the "after" filter know whether to restore.
	 *
	 * @param \Elementor\Core\Base\Document|null $document The document about to render.
	 */
	public static function maybe_switch_to_preview_product_query( $document ) {
		$switched = false;

		if ( $document && function_exists( 'wc_setup_product_data' ) ) {
			$document_id = $document->get_main_id();

			if ( self::is_woo_single_preview( $document_id ) ) {
				$preview_product_id = self::get_preview_product_id();

				if ( $preview_product_id ) {
					Plugin::$instance->db->switch_to_query( [
						'p'         => $preview_product_id,
						'post_type' => 'product',
					], true );

					$switched = true;
				}
			}
		}

		self::$preview_query_switch_stack[] = $switched;
	}

	/**
	 * Restore the query switched by maybe_switch_to_preview_product_query(), if any.
	 *
	 * @param string $content Unmodified — this is a restore point, not a content filter.
	 * @return string
	 */
	public static function maybe_restore_preview_product_query( $content ) {
		if ( array_pop( self::$preview_query_switch_stack ) ) {
			Plugin::$instance->db->restore_current_query();
		}

		return $content;
	}

	/**
	 * Whether `$document_id` is a WooCommerce single-product ( `product_single` )
	 * Theme Builder template at all — regardless of edit/preview mode.
	 *
	 * @param int $document_id The templately_library post being checked.
	 * @return bool
	 */
	private static function is_woo_single_document( $document_id ) {
		if ( get_post_meta( $document_id, Source::TYPE_META_KEY, true ) !== 'product_single' ) {
			return false;
		}

		return post_type_exists( 'product' );
	}

	/**
	 * Whether the current request is the editor/preview render of a WooCommerce
	 * single-product ( `product_single` ) Theme Builder template.
	 *
	 * @param int $document_id The templately_library post being rendered.
	 * @return bool
	 */
	private static function is_woo_single_preview( $document_id ) {
		if ( ! self::is_woo_single_document( $document_id ) ) {
			return false;
		}

		return Plugin::$instance->editor->is_edit_mode()
			|| Plugin::$instance->preview->is_preview_mode( $document_id )
			|| ( isset( $_GET['templately_library'], $_GET['preview_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Third preview-product switch point: overrides `global $post` for the
	 * `render_widget` ajax action — used when a widget with no JS
	 * `content_template()` is freshly added to the live editor canvas ( see
	 * register_preview_product_hooks() for the two dead ends this replaced ).
	 *
	 * `Ajax\Module::handle_ajax_request()` calls `Plugin::$instance->db
	 * ->switch_to_post( $editor_post_id )` — which sets `$GLOBALS['post']` +
	 * `setup_postdata()` directly — before dispatching to any action, and nothing
	 * in `ajax_render_widget()` ( including its own `query_posts()` call, which
	 * never touches `global $post` ) touches it again afterwards. So this has to
	 * override `global $post` a second time, the same way `switch_to_post()`
	 * itself does, timed to run strictly after it: `elementor/ajax/register_actions`
	 * fires right after that call and before the action-dispatch loop.
	 *
	 * Reads `$_REQUEST['actions']` — the same raw JSON `Ajax\Module` itself decodes
	 * moments later — read-only, purely to detect whether a `render_widget` call is
	 * queued in this batch; no nonce check needed for that inspection since nothing
	 * is mutated based on it beyond which product post to point `global $post` at.
	 *
	 * No restore call: a single, short-lived admin-ajax.php process that exits
	 * right after responding, so nothing persists past it.
	 */
	public static function maybe_switch_global_post_for_ajax_render() {
		if ( ! function_exists( 'wc_setup_product_data' ) || empty( $_REQUEST['actions'] ) || empty( $_REQUEST['editor_post_id'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only inspection, see docblock above.
		$actions = json_decode( wp_unslash( $_REQUEST['actions'] ), true );

		if ( ! is_array( $actions ) ) {
			return;
		}

		$has_render_widget = false;

		foreach ( $actions as $action_data ) {
			if ( isset( $action_data['action'] ) && 'render_widget' === $action_data['action'] ) {
				$has_render_widget = true;
				break;
			}
		}

		if ( ! $has_render_widget ) {
			return;
		}

		$document_id = absint( $_REQUEST['editor_post_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! self::is_woo_single_document( $document_id ) ) {
			return;
		}

		$preview_product_id = self::get_preview_product_id();

		if ( ! $preview_product_id ) {
			return;
		}

		$GLOBALS['post'] = get_post( $preview_product_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		setup_postdata( $GLOBALS['post'] );
	}

	/**
	 * Resolve the newest published product to use as the editor preview product.
	 * Matches the "latest product" approach used by the dynamic Theme Builder
	 * widgets ( Post_Content / Post_Title / Featured_Image ) and Elementor Pro.
	 *
	 * @return int Product ID, or 0 when the store has no published products.
	 */
	private static function get_preview_product_id() {
		$products = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'numberposts'    => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'suppress_filters' => false,
		] );

		return ! empty( $products ) ? (int) $products[0] : 0;
	}

	/**
	 * Print remaining enqueued styles with error handling.
	 */
	public static function print_style_tags() {
		try {
			$wp_styles = wp_styles();
			// handles dependencies
			$wp_styles->do_items();
			if ( is_object( $wp_styles ) && is_array( $wp_styles->queue ?? null ) ) {
				foreach ( $wp_styles->queue as $style ) {
					if ( is_string( $style ) && ! $wp_styles->query( $style, 'done' ) ) {
						$wp_styles->do_item( $style );
						$wp_styles->done[] = $style;
					}
				}
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Templately: Error printing styles - ' . $e->getMessage() );
			}
		}
	}

}