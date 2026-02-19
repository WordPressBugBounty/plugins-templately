<?php

namespace Templately\Builder\Managers;

use EbStyleHandler;
use EssentialBlocks\Modules\StyleHandler;
use Templately\Builder\PageTemplates;
use Templately\Builder\Source;
use Templately\Builder\ThemeBuilder;
use Templately\Builder\Types\BaseTemplate;
use Templately\Builder\Types\ThemeTemplate;
use ElementorPro\Modules\ThemeBuilder\Module;
use Elementor\Core\Files\CSS\Post as Post_CSS;
use ElementorPro\Plugin;
use Templately\Builder\TemplateLoader;

class LocationManager {
	/**
	 * @var array<string, ThemeTemplate>
	 */
	public $locations_queue   = [];
	public $locations_skipped = [];
	public $locations_printed = [];
	public $did_locations     = [];

	protected $locations = [];

	/**
	 * @var ThemeBuilder
	 */
	protected $builder;

	public function __construct( $builder ) {
		$this->builder = $builder;


		/**
		 * Priority is 13,
		 * Because it should be run after elementor & woocommerce
		 */
		add_filter( 'template_include', [ $this, 'template_include' ], 13 );

		/**
		 * Priority is 7,
		 * Because it should run before elementor
		 */
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_elementor_styles' ], 7 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_template_assets' ], 8 );
		add_action( 'wp_enqueue_scripts', [ $this, 'preload_gutenberg_template_styles' ], 9 );

		// Cache clearing hooks
		add_action( 'save_post', [ $this, 'clear_header_styles_cache' ] );
		add_action( 'delete_post', [ $this, 'clear_header_styles_cache' ] );
		add_action( 'trash_post', [ $this, 'clear_header_styles_cache' ] );
	}

	private function set_locations() {
		if(!empty($this->locations)) {
			return;
		}
		$this->locations = [
			'header'  => [
				'label'    => __( 'Header', 'templately' ),
				'multiple' => false
			],
			'footer'  => [
				'label'    => __( 'Footer', 'templately' ),
				'multiple' => false
			],
			'archive' => [
				'label'    => __( 'Archive', 'templately' ),
				'multiple' => false
			],
			'single'  => [
				'label'    => __( 'Single', 'templately' ),
				'multiple' => false
			]
		];
	}

	public function template_include( $template_path ) {
		$location             = '';
		$page_template_module = $this->get_template_module();

		/**
		 * Return if it is Elementor Template.
		 * This should be elementor's responsibility.
		 */

		if ( get_post_type( get_the_ID() ) === 'elementor_library' ) {
			return $template_path;
		}
		else if( $this->get_platform(get_the_ID()) === 'elementor' && !class_exists('Elementor\Plugin') ) {
			return $template_path;
		}

		if ( is_singular() ) {
			/**
			 * @var BaseTemplate $template
			 */
			$template = $this->builder::$templates_manager->get( get_the_ID() );

			if ( $template && $template->get_property( 'support_wp_page_templates' ) ) {
				$page_template_module->set_platform( $template->get_platform() );
				$wp_page_template = $template->get_meta( '_wp_page_template' );

				$_custom_template_path = $page_template_module->get_template_path( $wp_page_template );


				if ( empty( $_custom_template_path ) ) {
					$location = 'single';

					$templates_for_location = $this->builder::$conditions_manager->get_templates_by_location( $location );

					if ( empty( $templates_for_location ) ) {
						return $template_path;
					}

					$template_id = key( $templates_for_location );

					$template      = $templates_for_location[ $template_id ];
					$page_template = $template->get_meta( '_wp_page_template' );
					$platform      = $template->get_platform();

					if( $platform === 'elementor' && !class_exists('Elementor\Plugin') ) {
						return $template_path;
					}
					if ( ! empty( $platform ) ) {
						$page_template_module->set_platform( $platform );
					}
					$path = $page_template_module->get_template_path( $page_template );
					$page_template_module->set_print_callback( function () use ( $location ) {
						$this->do_location( $location );
					} );
					set_query_var( 'using_templately_template', 1 );

					return $path;
				}

				if ( $wp_page_template && $wp_page_template !== 'default' ) {
					set_query_var( 'using_templately_template', 1 );

					return $_custom_template_path;
				}
			}
		} else {
			$template = false;
		}

		if ( $template instanceof ThemeTemplate ) {
			$location = $template->get_location();
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$location = 'archive';
		} elseif ( is_archive() || is_tax() || is_home() || is_search() ) {
			$location = 'archive';
		} elseif ( is_singular() || is_404() ) {
			$location = 'single';
		}

		if ( $location ) {
			$templates_for_location = $this->builder::$conditions_manager->get_templates_by_location( $location );

			if ( empty( $templates_for_location ) ) {
				set_query_var( 'using_templately_template', 1 );

				return $template_path;
			}

			if ( 'single' === $location || 'archive' === $location ) {
				$template_id            = key( $templates_for_location );
				$template               = $templates_for_location[ $template_id ];
				$document_page_template = $template->get_meta( '_wp_page_template' );
				$platform               = $template->get_platform();

				if ( ! empty( $platform ) ) {
					$page_template_module->set_platform( $platform );
				}

				if ( $document_page_template ) {
					$page_template = $document_page_template;
				}

				if(!empty($template_id) && !empty($template) && $template->get_type() == "course_archive"){
					// $__post = $GLOBALS['post'];
					// learndash_course_grid_load_resources();
					// $GLOBALS['post'] = $__post;
					$GLOBALS['post'] = get_post($template_id);
				}
			}
		}
		$is_header_footer = 'header' === $location || 'footer' === $location;
		if ( empty( $page_template ) && ! $is_header_footer ) {
			$page_template = $page_template_module->get_header_footer_template();
		}

		if ( ! empty( $page_template ) ) {
			$path = $page_template_module->get_template_path( $page_template );

			if ( $path ) {
				$page_template_module->set_print_callback( function () use ( $location ) {
					$this->do_location( $location );
				} );
				set_query_var( 'using_templately_template', 1 );
				$template_path = $path;
			}
		}

		return $template_path;
	}

	private function get_platform($post_id) {
		$post_type = get_post_type( get_the_ID() );
		if ( $post_type == Source::CPT ) {
			$platform = get_post_meta( $post_id, Source::PLATFORM_META_KEY, true );
		} elseif ( get_post_meta( $post_id, '_elementor_edit_mode', true ) == 'builder' || $post_type == 'elementor_library' ) {
			$platform = 'elementor';
		} else {
			$platform = 'gutenberg';
		}
		return $platform;
	}

	/**
	 * Get page templating modules and set platform if needed.
	 *
	 * @param string $platform
	 *
	 * @return PageTemplates
	 */
	private function get_template_module( string $platform = '' ): PageTemplates {
		$module = templately()->theme_builder::$page_template_module;

		if ( ! empty( $platform ) ) {
			$module = $module->set_platform( $platform );
		}

		return $module;
	}

	public function get_location( $location ) {
		$locations = $this->get_locations();

		return $locations[ $location ] ?? [];
	}

	public function get_locations(): array {
		/**
		 * Don't know yet if we need it or not.
		 */
		$this->set_locations();

		$this->register_locations();

		return $this->locations;
	}

	public function register_locations() {
		if ( ! did_action( 'templately_locations' ) ) {
			do_action( 'templately_locations', $this );
		}
	}

	/**
	 * Getting the Idea From Elementor itself.
	 *
	 * @param $location
	 *
	 * @return bool
	 */
	public function do_location( $location ): bool {
		$templates_for_location = $this->builder::$conditions_manager->get_templates_by_location( $location );

		foreach ( $templates_for_location as $template_id => $template ) {
			$this->add_template_to_location( $location, $template_id );
		}

		if ( empty( $this->locations_queue[ $location ] ) ) {
			return false;
		}

		while ( ! empty( $this->locations_queue[ $location ] ) ) {
			$template_id = key( $this->locations_queue[ $location ] );
			$template    = $this->builder->get_template( $template_id );

			if ( ! $template || $this->is_printed( $location, $template_id ) ) {
				$this->skip_template_from_location( $location, $template_id );
				continue;
			}

			if ( empty( $documents_by_conditions[ $template_id ] ) ) {
				$post_status = get_post_status( $template_id );
				if ( 'publish' !== $post_status ) {
					$this->skip_template_from_location( $location, $template_id );
					continue;
				}
			}

			// Fire before printing to allow style preparation
			do_action("templately_printed_location", $template_id, $location, $template);

			$template->print_content();
			$this->did_locations[] = $location;

			$this->set_is_printed( $location, $template_id );
		}

		return true;
	}

	/**
	 * Enqueue CSS styles for Elementor-based templates.
	 *
	 * Uses Elementor's Post_CSS class to enqueue styles for all locations
	 * (header, footer, archive, single). Only runs for Elementor platform.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function enqueue_elementor_styles() {

		if (
			$this->get_platform(get_the_ID()) !== 'elementor' ||
			(
				class_exists('ElementorPro\Modules\ThemeBuilder\Module') &&
				Module::is_preview()
			)
		) {
			return;
		}



		$locations = $this->get_locations();

		if ( empty( $locations ) ) {
			return;
		}

		// if ( ! empty( $this->current_page_template ) ) {
		// 	$locations = $this->filter_page_template_locations( $locations );
		// }

		if(class_exists('Elementor\Core\Files\CSS\Post')){
			$current_post_id = get_the_ID();

			/** @var Post_CSS[] $css_files */
			$css_files = [];

			foreach ( $locations as $location => $settings ) {
				$templates_for_location = $this->builder::$conditions_manager->get_templates_by_location( $location );

				foreach ( $templates_for_location as $document ) {
					$post_id = $document->get_main_id();
					// Don't enqueue current post here (let the  preview/frontend components to handle it)
					if ( $current_post_id !== $post_id ) {
						$css_file = new Post_CSS( $post_id );
						$css_files[] = $css_file;
					}
				}
			}

			if ( ! empty( $css_files ) ) {
				// Enqueue the frontend styles manually also for pages that don't built with Elementor.
				// Plugin::elementor()->frontend->enqueue_styles();

				// Enqueue after the frontend styles to override them.
				foreach ( $css_files as $css_file ) {
					$css_file->enqueue();
				}

				if(class_exists('ElementorPro\Plugin')){
					/** @var \ElementorPro\Modules\ThemeBuilder\Module $theme_builder */
					$theme_builder    = Plugin::instance()->modules_manager->get_modules( 'theme-builder' );
					$location_manager = $theme_builder->get_locations_manager();
					remove_action( 'wp_enqueue_scripts', [ $location_manager, 'enqueue_styles' ] );
				}
			}
		}
	}

	/**
	 * Fire action for Essential Blocks to write CSS files.
	 *
	 * Iterates all Gutenberg template locations and fires the
	 * templately_printed_location action.
	 *
	 * Note: This action only triggers Essential Blocks to generate the CSS file
	 * and add the template ID to its processing list. It does NOT enqueue the
	 * CSS file immediately. The actual enqueue happens later in Essential Blocks'
	 * `enqueue_frontend_assets` method (hooked to wp_enqueue_scripts at priority 10),
	 * which relies on this action having already fired (at priority 8).
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function enqueue_template_assets() {
		$using_templately_builder = get_query_var( 'using_templately_template' );
		if ( ($using_templately_builder || TemplateLoader::is_header_footer()) && function_exists( 'templately' ) ) {
			$template_locations = [ 'header', 'footer', 'archive', 'single' ];
			foreach ( $template_locations as $location ) {
				$template = templately()->theme_builder::$conditions_manager->get_templates_by_location( $location );
				if ( empty( $template ) ) {
					continue;
				}
				$template = array_pop( $template );
				if ( $template->platform == 'gutenberg' ) {
					$template = is_array( $template ) ? array_pop( $template ) : $template;
					do_action("templately_printed_location", $template->get_main_id(), $location, $template);
				}
			}
		}
	}

	/**
	 * Pre-parse Gutenberg template blocks to enqueue styles in head.
	 *
	 * Iterates all template locations, parses block content, and triggers
	 * block style registration BEFORE wp_head() outputs styles. This ensures
	 * all block styles are properly enqueued rather than printed inline.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function preload_gutenberg_template_styles() {
		if ( ! function_exists( 'templately' ) || ! function_exists( 'parse_blocks' ) ) {
			return;
		}

		$template_locations = [ 'header' ];

		foreach ( $template_locations as $location ) {
			$templates = templately()->theme_builder::$conditions_manager->get_templates_by_location( $location );

			if ( empty( $templates ) ) {
				continue;
			}

			foreach ( $templates as $template ) {
				if ( $template->platform !== 'gutenberg' ) {
					continue;
				}

				$post_id = $template->get_main_id();
				$post    = get_post( $post_id );

				if ( ! $post ) {
					continue;
				}

				// Check for cached styles
				$transient_key = 'templately_header_styles_' . $post_id;
				$style_handles = get_transient( $transient_key );

				if ( false === $style_handles ) {
					if ( empty( $post->post_content ) ) {
						continue;
					}

					// Parse blocks and extract styles
					$blocks        = parse_blocks( $post->post_content );
					$style_handles = [];
					$this->get_block_styles_recursive( $blocks, $style_handles );

					// Cache the handles for a long time (e.g., 1 month)
					set_transient( $transient_key, $style_handles, MONTH_IN_SECONDS );
				}

				// Enqueue the styles
				if ( ! empty( $style_handles ) ) {
					foreach ( $style_handles as $handle ) {
						wp_enqueue_style( $handle );
					}
				}
			}
		}
	}

	/**
	 * Recursively extract style handles for all blocks.
	 *
	 * @since 3.0.0
	 * @param array $blocks Parsed blocks array.
	 * @param array $handles Reference to array of handles to populate.
	 * @return void
	 */
	private function get_block_styles_recursive( array $blocks, array &$handles ) {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return;
		}

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				// Get registered block type
				$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( $block['blockName'] );

				if ( $block_type ) {
					// Enqueue style handles (WP 5.9+)
					if ( ! empty( $block_type->style_handles ) ) {
						foreach ( $block_type->style_handles as $handle ) {
							if ( ! in_array( $handle, $handles ) ) {
								$handles[] = $handle;
							}
						}
					}
					// Legacy style property support
					if ( ! empty( $block_type->style ) ) {
						$styles = is_array( $block_type->style ) ? $block_type->style : [ $block_type->style ];
						foreach ( $styles as $style ) {
							if ( ! in_array( $style, $handles ) ) {
								$handles[] = $style;
							}
						}
					}
				}
			}

			// Process inner blocks recursively
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->get_block_styles_recursive( $block['innerBlocks'], $handles );
			}
		}
	}

	/**
	 * Deprecated wrapper for backward compatibility if needed,
	 * or we can just remove it since it was private.
	 * But for safety, I'll remove the old method and rely on the new flow.
	 * The previous method was private, so removal is safe within this class.
	 */

	/**
	 * @param string  $location
	 * @param integer $template_id
	 */
	public function add_template_to_location( string $location, int $template_id ) {
		if ( isset( $this->locations_skipped[ $location ][ $template_id ] ) ) {
			return;
		}

		if ( ! isset( $this->locations_queue[ $location ] ) ) {
			$this->locations_queue[ $location ] = [];
		}

		$this->locations_queue[ $location ][ $template_id ] = $template_id;
	}

	public function is_printed( $location, $template_id ): bool {
		return isset( $this->locations_printed[ $location ][ $template_id ] );
	}

	public function skip_template_from_location( $location, $template_id ) {
		$this->remove_template_from_location( $location, $template_id );

		if ( ! isset( $this->locations_skipped[ $location ] ) ) {
			$this->locations_skipped[ $location ] = [];
		}

		$this->locations_skipped[ $location ][ $template_id ] = $template_id;
	}

	public function remove_template_from_location( $location, $template_id ) {
		unset( $this->locations_queue[ $location ][ $template_id ] );
	}

	public function set_is_printed( $location, $template_id ) {
		if ( ! isset( $this->locations_printed[ $location ] ) ) {
			$this->locations_printed[ $location ] = [];
		}

		$this->locations_printed[ $location ][ $template_id ] = $template_id;
		$this->remove_template_from_location( $location, $template_id );
	}

	/**
	 * Clear the header styles cache for a specific post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function clear_header_styles_cache( $post_id ) {
		// Only clear if it might be a header template.
		// Since we don't strictly know if it IS a header without checking metadata (which might be what's changing),
		// we just clear the specific transient for this ID. It's harmless if the transient doesn't exist.
		delete_transient( 'templately_header_styles_' . $post_id );
	}
}