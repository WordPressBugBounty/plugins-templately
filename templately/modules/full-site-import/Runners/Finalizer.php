<?php

namespace Templately\Modules\FullSiteImport\Runners;


use Exception;
use Templately\Modules\AiContentMerger\Utils\AIContentHelper;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Modules\FullSiteImport\Utils\AIUtils;
use Templately\Modules\FullSiteImport\Utils\AIContentResolver;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Utils\Helper;

class Finalizer extends BaseRunner {
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

				$updated_ids = AIUtils::get_processed_pages_data($this->process_id);
				$ai_paths = AIUtils::generate_ai_file_paths(
					$this->session_id,
					$this->type,
					$this->sub_type,
					$old_template_id,
					$this->dir_path
				);
				if($this->is_ai_content($old_template_id) && !file_exists($ai_paths['ai_file_path'])){
					// Source-agnostic seam: whichever AI-content source owns this
					// process (classic per-page callback, chat-id pull, or a future
					// source) stages the .ai.json; otherwise the shared SSE-wait runs.
					AIContentResolver::ensure_page_ready([
						'session_id'          => $this->session_id,
						'process_id'          => $this->process_id,
						'content_id'          => $old_template_id,
						'ai_page_ids'         => $this->ai_page_ids,
						'updated_ids'         => $updated_ids,
						'sse_callback'        => [$this, 'sse_message'],
						'progress_id'         => 'ai_content_time',
						'additional_sse_data' => [
							'name'      => method_exists($this, 'get_name') ? $this->get_name() : '',
							'post_type' => $post_type,
							'id'        => $old_template_id,
						],
					]);
				}
				// Check if this is AI content, then merge the AI-generated content into the
				// original template. Session/path resolution + file IO live here (the
				// consumer); the pure platform merge is delegated to ai-content-merger's
				// standalone AIContentHelper::merge().
				if ( AIUtils::should_process_as_ai_content( $this->session_id, $this->type, $this->sub_type, $old_template_id, $this->ai_page_ids, $this->dir_path ) ) {
					$ai_file = $ai_paths['ai_file_path'];
					if ( AIUtils::has_ai_file( $ai_file ) && ! AIUtils::is_ai_file_skipped( $ai_file ) ) {
						$ai_json       = Utils::read_json_file( $ai_file );
						$original_json = Utils::read_json_file( $ai_paths['original_file'] );
						$merged        = AIContentHelper::merge( $ai_json, $original_json, $this->platform );

						// A merge that could not keep a block's attributes and its markup in step
						// leaves a block the editor reports as "unexpected or invalid content" and
						// a front end still showing the pre-AI copy. That is invisible from the
						// import log unless we say so, and it is the signal the post-import block
						// rebuild pass uses to decide which posts are worth revisiting.
						$parity_warnings = AIContentHelper::collect_parity_warnings();
						if ( ! empty( $parity_warnings ) ) {
							$this->record_parity_warnings( $old_template_id, $post_type, $parity_warnings );
						}

						// Debug file records the MERGED value pre-adoption — byte-faithful with
						// the old AIContentHelper::processAiContent().
						$this->write_ai_debug_file( $ai_paths['original_file'], $merged );

						if ( ! empty( $merged ) ) {
							$template_json = $merged;
						}
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

	/**
	 * Persist + broadcast the attribute/markup parity failures from one AI merge.
	 *
	 * Stored on the session (not only logged) because the consumer is the post-import block
	 * rebuild pass, which runs in the BROWSER after this request is long gone and needs to know
	 * which imported posts are worth opening. Kept deliberately non-fatal: a desynced block
	 * still renders, and failing an otherwise complete import over it would be worse than the
	 * banner it produces.
	 *
	 * @param int|string $template_id Source template id (pack-side).
	 * @param string     $post_type   Post type being finalized.
	 * @param array      $warnings    From `AIContentHelper::collect_parity_warnings()`.
	 */
	private function record_parity_warnings( $template_id, $post_type, array $warnings ) {
		$new_post_id = $this->map_post_ids[ $template_id ] ?? null;

		$existing = SessionData::get( $this->session_id, 'parity_warnings' );
		$existing = is_array( $existing ) ? $existing : [];

		$existing[] = [
			'template_id' => $template_id,
			'post_id'     => $new_post_id,
			'post_type'   => $post_type,
			'warnings'    => $warnings,
		];

		SessionData::set( $this->session_id, 'parity_warnings', $existing );

		$blocks = [];
		foreach ( $warnings as $warning ) {
			$blocks[] = $warning['blockName'] . ( $warning['attribute'] ? ' (' . $warning['attribute'] . ')' : '' );
		}

		$this->sse_message( [
			'type'    => 'eventLog',
			'action'  => 'eventLog',
			'info'    => 'ai_content_parity',
			'results' => sprintf(
				/* translators: 1: number of blocks, 2: post type, 3: comma-separated block names */
				__( '%1$d block(s) in this %2$s kept their original text in the markup: %3$s', 'templately' ),
				count( $warnings ),
				$post_type,
				implode( ', ', array_unique( $blocks ) )
			),
		] );

		Helper::log(
			[
				'context'  => 'ai_content_parity',
				'post_id'  => $new_post_id,
				'warnings' => $warnings,
			],
			'fsi_event'
		);
	}

	public function post_log($id, $size_dimension = null){
		$this->log(-1, "Imported attachment: $id" . ( $size_dimension ? " - $size_dimension" : ''), 'eventLog');
	}

	/**
	 * Write the merged AI content to a sibling debug file (.ao.json) when a dev/debug
	 * constant is set. Relocated verbatim from AIContentHelper::writeDebugFile() when that
	 * trait became a standalone class (spec 022 FR-011) — the session/file-IO orchestration
	 * that surrounds the pure merge is the consumer's responsibility.
	 */
	private function write_ai_debug_file( $original_file, $data, $type = 'ao' ) {
		if ((defined('TEMPLATELY_DEV') && TEMPLATELY_DEV) || (defined('IMPORT_DEBUG') && IMPORT_DEBUG)) {
			$replace = ".{$type}.json";
			$debug_file = str_replace('.json', $replace, $original_file);
			file_put_contents($debug_file, json_encode($data));
		}
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
