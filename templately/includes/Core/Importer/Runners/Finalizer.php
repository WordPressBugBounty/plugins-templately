<?php

namespace Templately\Core\Importer\Runners;


use Exception;
use Templately\Core\Importer\Utils\AIContentHelper;
use Templately\Core\Importer\Utils\Utils;
use Templately\Core\Importer\Utils\AIUtils;
use Templately\Utils\Helper;

class Finalizer extends BaseRunner {
	use AIContentHelper;
	private $options       = [];
	private $type_to_check = [ 'templates', 'content' ];
	private $imported_data;

	public $type     = '';
	private $data     = [];
	public $sub_type = '';
	private $extra_content;

	private $total_counts    = 0;

	/**
	 * @var array|mixed
	 */
	protected $map_post_ids = [];
	/**
	 * @var array|mixed
	 */
	protected $map_term_ids = [];

	public function get_name(): string {
		return 'finalize';
	}

	public function get_label(): string {
		return __( 'Finalizing Your Imports', 'templately' );
	}

	public function log_message(): string {
		return __( 'Finalizing Your Imports', 'templately' );
	}

	public function should_run( $param_data, $imported_data = [] ): bool {
		$data = [];

		foreach ( $this->type_to_check as $type ) {
			$contents = ! empty ( $this->manifest[ $type ] ) ? $this->manifest[ $type ] : [];
			if ( $type == 'templates' ) {
				// $imported_data["templates"]["__attachments"][691]
				$this->prepare( $data, $contents, $type, null, $imported_data );
			} else {
				foreach ( $contents as $post_type => $templates ) {
					// $imported_data["content"]["__attachments"][$post_type][11]
					$this->prepare( $data, $templates, $type, $post_type, $imported_data );
				}
			}
		}
		$this->options = &$data;

		return ! empty( $data ) || $this->platform == 'gutenberg';
	}

	private function prepare( &$data, $templates, $type, $sub_type = null, $imported_data = [] ) {
		if ( empty( $templates ) || ! is_array( $templates ) ) {
			return;
		}
		foreach ( $templates as $id => $template ) {
			// Check for __attachments in both template and imported_data
			$has_attachment = !empty($template['__attachments']);

			if (!$has_attachment && !empty($imported_data)) {
				if ($sub_type) {
					// $imported_data["content"]["__attachments"]["page"][11]
					$has_attachment = isset($imported_data[$type]['__attachments'][$sub_type][$id]);
				} else {
					// $imported_data["templates"]["__attachments"][691]
					$has_attachment = isset($imported_data[$type]['__attachments'][$id]);
				}
			}

			// Pick up docs the import runners flagged as carrying source-site links
			// (Mega Menu / nav / button URLs) so the finalize loop rewrites them to the
			// real imported permalinks — same opt-in mechanism as __attachments above.
			// $imported_data["templates"]["__link_rewrites"][52]
			// $imported_data["content"]["page"]["__link_rewrites"][35]
			$needs_link_rewrite = $sub_type
				? isset( $imported_data[ $type ][ $sub_type ]['__link_rewrites'][ $id ] )
				: isset( $imported_data[ $type ]['__link_rewrites'][ $id ] );

			if ( ! isset( $template['data'] ) && !$has_attachment && !$needs_link_rewrite && !isset($template['has_logo']) && !$this->is_ai_content($id) && !isset($template["page_settings"]["fluent_cart_store_settings"]) ) {
				continue;
			}

			// if ( ! isset( $template['data']['form'] ) && ! isset( $template['data']['nav_menus'] )) {
			// 	continue;
			// }

			$this->total_counts += 1;

			if ( $sub_type ) {
				$data[ $type ][ $sub_type ][ $id ] = $template;
			} else {
				$data[ $type ][ $id ] = $template;
			}
		}
	}

	public function import( $data, $imported_data ): array {
		$this->data = &$data;
		$this->imported_data = &$imported_data;

		$this->json->imported_data = $this->imported_data;
		$this->json->map_post_ids  = Utils::map_old_new_post_ids( $this->imported_data );
		$this->json->map_term_ids  = Utils::map_old_new_term_ids( $this->imported_data );
		if ( $this->manifest['platform'] === 'elementor' ) {
			$this->json->set_source_url( $this->manifest['site'] ?? '' );
			$this->json->set_source_pages( $this->build_source_pages_map() );
		}
		if ( ! empty( $imported_data['extra-content'] ) ) {
			$this->extra_content = $imported_data['extra-content'];
		}

		add_action('templately_import.finalize_gutenberg_attachment', [$this, 'post_log'], 10, 2);

		$this->update_settings();

		$this->loop( $this->options, function($type, $contents ) {
			$this->type = $type;

			if ( $type == 'templates' ) {
				$this->finalize_imports( $contents, $type );
			} else {
				$this->loop( $contents, function($post_type, $templates ) use($type) {
					$this->sub_type = $post_type;
					$this->finalize_imports( $templates, $type, $post_type );
				}, $type);
			}
		});

		if ( $this->platform == 'gutenberg' ) {
			$this->regenerate_assets();
		}

		return [];
	}

	/**
	 * Build the ORIGINAL-slug => imported-permalink map handed to ElementorHelper so
	 * links can resolve to the page WordPress actually created (slug-change-proof).
	 *
	 * Keyed by the pack's original slug — which is what stored links still carry —
	 * with the value being get_permalink() of the imported post, so a conflict rename
	 * (about-us → about-us-2) is transparently followed. Pages win over other post
	 * types on a slug collision. WooCommerce system pages (shop, cart, checkout,
	 * my-account) are added as aliases since they live outside the manifest content.
	 *
	 * @return array
	 */
	private function build_source_pages_map(): array {
		$map          = [];
		$map_post_ids = $this->json->map_post_ids;
		$content      = $this->manifest['content'] ?? [];

		if ( is_array( $content ) ) {
			foreach ( $content as $post_type => $items ) {
				if ( ! is_array( $items ) ) {
					continue;
				}

				foreach ( $items as $old_id => $entry ) {
					$slug = isset( $entry['slug'] ) ? strtolower( trim( (string) $entry['slug'] ) ) : '';
					if ( '' === $slug ) {
						continue;
					}

					$new_id = $map_post_ids[ $old_id ] ?? null;
					if ( empty( $new_id ) ) {
						continue;
					}

					$permalink = get_permalink( $new_id );
					if ( ! $permalink || is_wp_error( $permalink ) ) {
						continue;
					}

					// First match wins, but a real `page` always overrides another
					// post type that happens to share the slug.
					if ( ! isset( $map[ $slug ] ) || 'page' === $post_type ) {
						$map[ $slug ] = $permalink;
					}
				}
			}
		}

		$this->add_woocommerce_page_aliases( $map );

		return $map;
	}

	/**
	 * Add WooCommerce system-page slugs to the resolution map. These pages are not in
	 * the manifest content, so menu links to them (often relative, e.g. /demo/shop,
	 * /demo/sign-in) would otherwise only get the plain domain swap.
	 *
	 * Heuristic: the demo's my-account page may be titled/slugged "sign-in", "login",
	 * etc.; those common aliases are mapped to this site's WooCommerce my-account page.
	 * Manifest pages already in the map are never overwritten.
	 *
	 * @param array $map slug => permalink (by reference).
	 *
	 * @return void
	 */
	private function add_woocommerce_page_aliases( array &$map ) {
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			return;
		}

		$aliases = [
			'shop'       => [ 'shop', 'store', 'products' ],
			'cart'       => [ 'cart' ],
			'checkout'   => [ 'checkout' ],
			'myaccount'  => [ 'my-account', 'myaccount', 'account', 'sign-in', 'signin', 'login', 'log-in', 'dashboard' ],
		];

		foreach ( $aliases as $wc_page => $slugs ) {
			$permalink = wc_get_page_permalink( $wc_page );
			if ( ! $permalink ) {
				continue;
			}

			foreach ( $slugs as $slug ) {
				if ( ! isset( $map[ $slug ] ) ) {
					$map[ $slug ] = $permalink;
				}
			}
		}
	}

	private function regenerate_assets() {
		$upload_dir = wp_upload_dir();
		if ( is_dir( $upload_dir['basedir'] . '/eb-style/' ) ) {
			array_map( 'unlink', glob( $upload_dir['basedir'] . '/eb-style/*.min.css' ) );
			rmdir( $upload_dir['basedir'] . '/eb-style/' );
		}
	}

	private function finalize_imports( $templates, $type, $post_type = null ) {
		// used for counting
		$processed = $this->get_loop_result([], 'finalized_imports', false);
		$path      = $this->dir_path . $this->type . DIRECTORY_SEPARATOR;

		if ( ! empty( $this->sub_type ) ) {
			$path     .= $this->sub_type . DIRECTORY_SEPARATOR;
		}

		$this->loop( $templates, function($old_template_id, $template_settings ) use($type, $post_type, $path, $processed) {
			try {

				if($post_type && isset($this->imported_data[$type]['__attachments'][$post_type][$old_template_id])){
					$template_settings['__attachments'] = $this->imported_data[$type]['__attachments'][$post_type][$old_template_id];
				}
				else if(empty($post_type) && isset($this->imported_data[$type]['__attachments'][$old_template_id])){
					$template_settings['__attachments'] = $this->imported_data[$type]['__attachments'][$old_template_id];
				}

				// Read the original template file for non-AI content
				$original_file = $path . "{$old_template_id}.json";
				$template_json = Utils::read_json_file($original_file);

				$processed_pages = get_option("templately_ai_processed_pages", []);
				$updated_ids = $processed_pages[$this->process_id] ?? [];
				$ai_paths = $this->generateAiFilePaths($old_template_id);
				if($this->is_ai_content($old_template_id) && !file_exists($ai_paths['ai_file_path'])){
					// Use the static timeout-aware wait handler from AIUtils
					AIUtils::handle_sse_wait_with_timeout(
						$this->session_id,
						'ai_content_time',
						$updated_ids,
						$this->ai_page_ids,
						[$this, 'sse_message'],
						[
							'name' => method_exists($this, 'get_name') ? $this->get_name() : '',
							'post_type' => $post_type,
							'id' => $old_template_id,
						],
						$old_template_id // Pass template ID for local site polling
					);
				}
				// Check if this is AI content before processing
				if ($this->isAiContent($old_template_id)) {
					// Process AI content using AIContentHelper trait
					$ai_result = $this->processAiContent($old_template_id);
					if($ai_result['is_ai'] && !empty($ai_result['template_json'])){
						$template_json = $ai_result['template_json'];
					}
				}

				$params = $this->origin->get_request_params();
				$this->json->prepare( $template_json, $template_settings, $this->extra_content['form'][ $old_template_id ] ?? [], $params )->update();

				$processed[] = $old_template_id;
				// Add the template to the processed templates and update the session data
				$this->set_loop_result( $processed, 'finalized_imports');
				// Broadcast Log
				$progress = floor( ( 100 * count($processed) ) / $this->total_counts );
				if(empty($progress)){
					$xyz = 0;
				}
				$this->log( $progress );

			} catch ( Exception $e ) {

			}


		}, "$type-$post_type");
	}

	public function post_log($id, $size_dimension = null){
		$this->log(-1, "Imported attachment: $id" . ( $size_dimension ? " - $size_dimension" : ''), 'eventLog');
	}

	private function update_settings() {
		$data = $this->data;
		$map_post_ids = $this->json->map_post_ids;
		$saved_options = get_option('fluent_cart_store_settings', []);
		$mapped_keys = [
			'checkout_page_id',
			'custom_payment_page_id',
			'registration_page_id',
			'login_page_id',
			'cart_page_id',
			'receipt_page_id',
			'shop_page_id',
			'customer_profile_page_id',
		];

		foreach ($mapped_keys as $key) {
			$old_id = $saved_options[$key] ?? null;
			if (!empty($old_id) && isset($map_post_ids[$old_id])) {
				$saved_options[$key] = $map_post_ids[$old_id];
			}
			else if(isset($saved_options[$key])){
				unset($saved_options[$key]);
			}
		}

		update_option('fluent_cart_store_settings', $saved_options);
	}





}
