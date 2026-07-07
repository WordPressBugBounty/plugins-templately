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
		if ( ! $this->is_woo_single_preview( $document_id ) ) {
			return;
		}

		$preview_product_id = $this->get_preview_product_id();
		if ( $preview_product_id ) {
			wc_setup_product_data( $preview_product_id );
		}
	}

	/**
	 * Whether the current request is the editor/preview render of a WooCommerce
	 * single-product ( `product_single` ) Theme Builder template.
	 *
	 * @param int $document_id The templately_library post being rendered.
	 * @return bool
	 */
	private function is_woo_single_preview( $document_id ) {
		if ( get_post_meta( $document_id, Source::TYPE_META_KEY, true ) !== 'product_single' ) {
			return false;
		}

		if ( ! post_type_exists( 'product' ) ) {
			return false;
		}

		return Plugin::$instance->editor->is_edit_mode()
			|| Plugin::$instance->preview->is_preview_mode( $document_id )
			|| ( isset( $_GET['templately_library'], $_GET['preview_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Resolve the newest published product to use as the editor preview product.
	 * Matches the "latest product" approach used by the dynamic Theme Builder
	 * widgets ( Post_Content / Post_Title / Featured_Image ) and Elementor Pro.
	 *
	 * @return int Product ID, or 0 when the store has no published products.
	 */
	private function get_preview_product_id() {
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