<?php

namespace Templately\Modules\FullSiteImport\Concerns;

use Elementor\Plugin;
use Error;
use Exception;
use Templately\Modules\FullSiteImport\Exception\FatalErrorException;
use Templately\Modules\FullSiteImport\Exception\RetryableErrorException;
use Templately\Modules\FullSiteImport\Exception\UnknownErrorException;
use Templately\Modules\FullSiteImport\Runners\Finalizer;
use Templately\Modules\FullSiteImport\Utils\LogHandler;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Modules\FullSiteImport\Utils\AIUtils;
use Templately\Utils\Helper;
use Templately\Utils\Options;

/**
 * TracksRevert — extracted verbatim from FullSiteImport (behavior-preserving).
 * Composed back into FullSiteImport via `use`; $this and method resolution unchanged.
 */
trait TracksRevert {
	// === DEPRECATED / UNUSED (kept intentionally) ===
	public function redirect_for_archives($link, $post_id) {
		$archive_settings = get_option('templately_post_archive');
		if (!empty($archive_settings) && intval($archive_settings['post_id']) === intval($post_id)) {
			$link = str_replace($post_id, $archive_settings['archive_id'], $link);
		}

		return $link;
	}

	public function update_imported_list($type, $id) {
		$imported_list = get_option('templately_fsi_imported_list', []);
		if(!in_array($id, $imported_list[$type] ?? [])){
			$imported_list[$type][] = $id;
			update_option('templately_fsi_imported_list', $imported_list, false);
		}
	}

	/**
	 *
	 *
	 * @return void
	 */
	protected function add_revert_hooks() {
		add_action('wp_insert_post', function ($post_id) {
			$this->update_imported_list('posts', $post_id);
		});
		add_action('add_attachment', function ($post_id) {
			$this->update_imported_list('attachment', $post_id);
		});
		add_action('created_term', function ($term_id, $tt_id, $taxonomy, $args) {
			$this->update_imported_list('term', [$term_id, $taxonomy]);
		}, 10, 4);
		add_action('registered_taxonomy', function ($taxonomy, $object_type, $taxonomy_object) {
			$this->update_imported_list('taxonomy', $taxonomy);
		}, 10, 3);
		add_action('fluentform/form_imported', function ($formId){
			$this->update_imported_list('fluentform', $formId);
		}, 10, 1);
	}

}
