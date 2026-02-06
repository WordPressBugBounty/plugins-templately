<?php
/**
 * Fluent Cart Product Importer
 *
 * Imports Fluent Cart products from JSON format with images
 * Replicates the functionality of Fluent Cart's DummyProductService
 *
 * @package Templately\Exporter\Core
 * @since 1.3.4
 */

namespace Templately\Core\Importer\Utils;

use Exception;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\Async\ImageAttachService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

/**
 * FluentImport Class
 *
 * Handles importing Fluent Cart products with their images from JSON format
 * Uses the same approach as Fluent Cart's DummyProductService
 *
 * @since 1.3.4
 */
class FluentImport {

	/**
	 * Import file path
	 *
	 * @var string
	 * @since 1.3.4
	 */
	private $import_path;

	/**
	 * Images directory path
	 *
	 * @var string
	 * @since 1.3.4
	 */
	private $images_dir;

	/**
	 * Import statistics
	 *
	 * @var array
	 * @since 1.3.4
	 */
	private $stats = [
		'products_imported'   => 0,
		'products_failed'     => 0,
		'variations_imported' => 0,
		'images_imported'     => 0,
	];

	/**
	 * Product ID mappings (original ID => new ID)
	 *
	 * @var array
	 * @since 1.3.4
	 */
	private $product_id_mappings = [
		'succeed' => [],
		'failed'  => [],
	];

	/**
	 * Import errors
	 *
	 * @var array
	 * @since 1.3.4
	 */
	private $errors = [];

	/**
	 * Products array for action hooks
	 *
	 * @var array
	 * @since 1.3.4
	 */
	public $products = [];

	/**
	 * Constructor
	 *
	 * @param string $import_path Path to the JSON file to import
	 * @since 1.3.4
	 */
	public function __construct( $import_path ) {
		$this->import_path = $import_path;
		$this->images_dir  = dirname( $import_path ) . '/images';
		$this->require_files();
	}

	/**
	 * Require necessary WordPress files
	 *
	 * @return void
	 * @since 1.3.4
	 */
	private function require_files() {
		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_create_term' ) ) {
			require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
		}
	}

	/**
	 * Import products from JSON
	 *
	 * @return array Structured import result with status, errors, and summary
	 * @since 1.3.4
	 */
	public function import() {
		try {
			// Check if file exists
			if ( ! file_exists( $this->import_path ) ) {
				throw new Exception( 'Import file not found: ' . $this->import_path );
			}

			// Check if images directory exists
			if ( ! file_exists( $this->images_dir ) ) {
				throw new Exception( 'Images directory not found: ' . $this->images_dir );
			}

			// Load JSON
			$json = file_get_contents( $this->import_path );
			if ( $json === false ) {
				throw new Exception( 'Failed to read JSON file' );
			}

			$products = json_decode( $json, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				throw new Exception( 'Failed to parse JSON: ' . json_last_error_msg() );
			}

			if ( empty( $products ) || ! is_array( $products ) ) {
				return $this->format_result( 'success', [], [] );
			}

			// Store products for action hooks (similar to WPImport::$posts)
			$this->products = $this->prepare_products_for_hooks( $products );

			// Trigger Templately-specific import_start action
			do_action( 'templately_import_start', $this );

			// Import each product
			foreach ( $products as $product_data ) {
				// Prepare result array for this product
				$result = [
					'succeed' => $this->product_id_mappings['succeed'],
					'failed'  => $this->product_id_mappings['failed'],
				];

				try {
					$product_id = $this->insert_product( $product_data );
					$this->stats['products_imported']++;

					// Track product ID mapping if original ID exists
					if ( isset( $product_data['id'] ) ) {
						$this->product_id_mappings['succeed'][ $product_data['id'] ] = $product_id;
					}

					// Update result with new mapping
					$result['succeed'] = $this->product_id_mappings['succeed'];

					// Prepare post-like data for action hook
					$post_data = $this->prepare_post_data_for_hook( $product_data, $product_id );

					// Trigger process_post action (similar to WPImport)
					do_action( 'templately_import.process_post', $post_data, $result, $this );

				} catch ( Exception $e ) {
					$this->stats['products_failed']++;
					$error_msg = 'FluentImport: Failed to import product: ' . $e->getMessage();
					$this->errors[] = $error_msg;
					error_log( $error_msg );

					// Track failed product id if original id exists
					if ( isset( $product_data['id'] ) ) {
						$this->product_id_mappings['failed'][] = $product_data['id'];
					}

					// Update result with failed ID
					$result['failed'] = $this->product_id_mappings['failed'];

					// Prepare post-like data for action hook (even for failed imports)
					$post_data = $this->prepare_post_data_for_hook( $product_data, null );

					// Trigger process_post action for failed import
					do_action( 'templately_import.process_post', $post_data, $result, $this );
				}
			}

			return $this->format_result( 'success', $this->errors, $this->product_id_mappings );

		} catch ( Exception $e ) {
			$error_msg = 'Import failed: ' . $e->getMessage();
			return $this->format_result( 'error', [ $error_msg ], [] );
		}
	}

	/**
	 * Format import result in structured JSON format
	 *
	 * @param string $status Status of import (success or error)
	 * @param array  $errors Array of error messages
	 * @param array  $product_mappings Product ID mappings
	 * @return array Formatted result
	 * @since 1.3.4
	 */
	private function format_result( $status, $errors, $product_mappings ) {
		return [
			'status' => $status,
			'errors' => $errors,
			'summary' => [
				'terms' => [
					'succeed' => [],
					'failed'  => [],
				],
				'posts' => [
					'succeed' => $product_mappings['succeed'] ?? [],
					'failed'  => $product_mappings['failed'] ?? [],
				],
			],
		];
	}

	/**
	 * Prepare products array for action hooks
	 * Converts products to a format similar to WPImport::$posts
	 *
	 * @param array $products Products array from JSON
	 * @return array Prepared products array
	 * @since 1.3.4
	 */
	private function prepare_products_for_hooks( $products ) {
		$prepared = [];
		foreach ( $products as $product ) {
			$prepared[] = [
				'post_id'    => $product['id'] ?? 0,
				'post_type'  => 'fluent-products',
				'post_title' => $product['post_title'] ?? '',
			];
		}
		return $prepared;
	}

	/**
	 * Prepare post-like data for action hook
	 * Converts product data to a format similar to WPImport post data
	 *
	 * @param array $product_data Product data from JSON
	 * @param int|null $product_id New product ID (null if failed)
	 * @return array Post-like data array
	 * @since 1.3.4
	 */
	private function prepare_post_data_for_hook( $product_data, $product_id ) {
		return [
			'post_id'    => $product_data['id'] ?? 0,
			'post_type'  => 'fluent-products',
			'post_title' => $product_data['post_title'] ?? '',
			'new_id'     => $product_id,
		];
	}

	/**
	 * Insert a single product
	 * Replicates DummyProductService::insert() method
	 *
	 * @param array $product Product data array
	 * @return int The ID of the created product
	 * @throws Exception If product insertion fails
	 * @since 1.3.4
	 */
	private function insert_product( $product ) {
		$product_name = Str::slug( $product['post_title'], '-', null );
		$now          = DateTime::gmtNow();
		$created_date = $now->format( 'Y-m-d H:i:s' );
		$product_name_suffix = $now->format( 'd-m-Y-H-i-s' );

		$data = [
			'post_author'           => get_current_user_id(),
			'post_date'             => $created_date,
			'post_date_gmt'         => $created_date,
			'post_content_filtered' => '',
			'post_status'           => 'publish',
			'post_type'             => 'fluent-products',
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => $product_name . '-' . $product_name_suffix,
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => $created_date,
			'post_modified_gmt'     => $created_date,
			'post_parent'           => 0,
			'menu_order'            => 0,
			'post_mime_type'        => '',
			'guid'                  => get_site_url() . '/?items=' . $product_name . '-' . $product_name_suffix,
		];

		$product      = array_merge( $product, $data );
		$detail       = Arr::get( $product, 'detail', [] );
		$variant_data = Arr::get( $product, 'variants', [] );
		$gallery_images = Arr::get( $product, 'gallery', [] );
		$categories   = Arr::get( $product, 'categories', [] );

		// Create product using Fluent Cart's ORM
		$product_model = Product::query()->create(
			Arr::except( $product, [ 'detail', 'variants', 'gallery', 'categories' ] )
		);

		if ( ! $product_model ) {
			throw new Exception( 'Failed to create product' );
		}

		// Attach categories
		$this->attach_terms( $categories, $product_model->ID );

		// Attach gallery images
		if ( ! empty( $gallery_images ) ) {
			$this->attach_images_to_product( $product_model->toArray(), $gallery_images );

			// Set featured image from first gallery image
			$gallery_image_with_id = get_post_meta( $product_model->ID, 'fluent-products-gallery-image', true );
			if ( isset( $gallery_image_with_id[0] ) ) {
				set_post_thumbnail( $product_model->ID, Arr::get( $gallery_image_with_id, '0.id' ) );
			}
		}

		// Create variants
		if ( ! empty( $variant_data ) ) {
			$variants = $product_model->variants()->createMany( $variant_data );
			$this->stats['variations_imported'] += $variants->count();

			// Attach images to variants
			foreach ( $variants as $index => $variant ) {
				$images = Arr::get( $variant_data, $index . '.images', [] );
				if ( is_array( $images ) && count( $images ) ) {
					$this->attach_images_to_variant( $variant->id, $images );
				}
			}

			// Create product detail with default variation
			if ( ! empty( $detail ) ) {
				$detail['post_id']              = $product_model->ID;
				$detail['default_variation_id'] = $variants->first()->id;
				$product_model->detail()->create( $detail );
			}
		} else {
			// Create product detail without default variation
			if ( ! empty( $detail ) ) {
				$detail['post_id'] = $product_model->ID;
				$product_model->detail()->create( $detail );
			}
		}

		return $product_model->ID;
	}

	/**
	 * Attach terms to product
	 * Replicates DummyProductService::attachTerms() method
	 *
	 * @param array|string $categories Categories to attach
	 * @param int          $post_id Post ID
	 * @return void
	 * @since 1.3.4
	 */
	private function attach_terms( $categories, $post_id ) {
		if ( is_string( $categories ) ) {
			$categories = explode( ',', $categories );
		}

		$term_ids = [];

		if ( is_array( $categories ) ) {
			foreach ( $categories as $category ) {
				$term = wp_create_term( $category, 'product-categories' );
				if ( ! is_wp_error( $term ) ) {
					$term_ids[] = $term['term_id'];
				}
			}
		}

		if ( ! empty( $term_ids ) ) {
			wp_set_post_terms( $post_id, $term_ids, 'product-categories' );
		}
	}

	/**
	 * Attach images to product
	 * Uses ImageAttachService similar to DummyProductService
	 *
	 * @param array $product Product array
	 * @param array $images Array of image paths
	 * @return void
	 * @since 1.3.4
	 */
	private function attach_images_to_product( array $product, array $images ) {
		if ( empty( $images ) ) {
			return;
		}

		$gallery = [];

		foreach ( $images as $image_path ) {
			$value = $this->add_image_from_path( $image_path, $product['post_title'] );
			if ( ! empty( $value ) ) {
				$gallery[] = $value;
				$this->stats['images_imported']++;
			}
		}

		if ( ! empty( $gallery ) ) {
			update_post_meta( $product['ID'], 'fluent-products-gallery-image', $gallery );
		}
	}

	/**
	 * Attach images to variant
	 * Uses ImageAttachService similar to DummyProductService
	 *
	 * @param int   $variant_id Variant ID
	 * @param array $images Array of image paths
	 * @return void
	 * @since 1.3.4
	 */
	private function attach_images_to_variant( $variant_id, array $images ) {
		if ( empty( $images ) ) {
			return;
		}

		// Use ProductVariation model to access media() relationship
		$variant = \FluentCart\App\Models\ProductVariation::query()->find( $variant_id );
		if ( empty( $variant ) ) {
			return;
		}

		$meta_value = [];
		foreach ( $images as $image_path ) {
			$value = $this->add_image_from_path( $image_path, $variant['variation_title'] );
			if ( ! empty( $value ) ) {
				$meta_value[] = $value;
				$this->stats['images_imported']++;
			}
		}

		if ( ! empty( $meta_value ) ) {
			// Use media() relationship like ImageAttachService does
			// meta_value should be an array, not serialized
			$media = [
				'meta_value'  => $meta_value,
				'object_id'   => $variant['id'],
				'object_type' => 'product_variant_info',
				'meta_key'    => 'product_thumbnail',
			];
			$variant->media()->create( $media );
		}
	}

	/**
	 * Add image from local path
	 * Similar to ImageAttachService::addImageFromUrl() but for local files
	 *
	 * @param string $image_path Relative image path (e.g., 'images/photo.jpg')
	 * @param string $title Image title
	 * @return array Image data array with id, title, and url
	 * @since 1.3.4
	 */
	private function add_image_from_path( $image_path, $title ) {
		// Convert relative path to absolute
		$full_path = dirname( $this->import_path ) . '/' . $image_path;

		if ( ! file_exists( $full_path ) ) {
			error_log( 'FluentImport: Image file not found: ' . $full_path );
			return [];
		}

		// Check if image already exists in media library
		$filename = basename( $full_path );
		global $wpdb;
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE %s",
				'%' . $wpdb->esc_like( $filename )
			)
		);

		if ( $existing ) {
			$media = wp_prepare_attachment_for_js( $existing );
			return [
				'id'    => $existing,
				'title' => $media['title'],
				'url'   => $media['url'],
			];
		}

		// Upload image to WordPress media library
		$upload_dir = wp_upload_dir();
		$dest_file  = $upload_dir['path'] . '/' . $filename;

		// Copy file to uploads directory
		if ( ! copy( $full_path, $dest_file ) ) {
			error_log( 'FluentImport: Failed to copy image: ' . $full_path );
			return [];
		}

		// Create attachment
		$attachment_data = [
			'post_mime_type' => mime_content_type( $dest_file ),
			'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];

		$attachment_id = wp_insert_attachment( $attachment_data, $dest_file );
		if ( is_wp_error( $attachment_id ) ) {
			error_log( 'FluentImport: Failed to create attachment: ' . $attachment_id->get_error_message() );
			return [];
		}

		// Generate attachment metadata
		$attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $dest_file );
		wp_update_attachment_metadata( $attachment_id, $attachment_metadata );

		$media = wp_prepare_attachment_for_js( $attachment_id );
		return [
			'id'    => $attachment_id,
			'title' => $media['title'],
			'url'   => $media['url'],
		];
	}
}

