<?php

namespace Templately\Core;

use Templately\Modules\Auth\REST\Profile;
use Templately\Utils\Base;
use Templately\Utils\Options;

class Migrator extends Base {
	/**
	 * Options Database
	 *
	 * @var Options|null
	 */
	private $options = null;

	public function __construct(){
		$this->options = Options::get_instance();
		add_action('admin_init', [ $this, 'init' ]);
	}

	public function init(){
		$_old_version = $this->options->get_option( '_templately_migrate' );

		/**
		 * Migration for v1.3.6 to v2.0.1
		 */
		if( \version_compare(TEMPLATELY_VERSION, '2.0.1', '=') && \version_compare($_old_version, '1.3.6', '=') ) {
			$user_choice = $this->options->get_option('_templately_user_login_choice');
			$user_id = false;
			if( ! empty( $user_choice ) && isset($user_choice['choice']) ) {
				$user_id = intval($user_choice['id']);
			}

			$cloud_activity = $this->options->get_option('_templately_cloud_last_activity');
			$user_profile   = $this->options->get_option('_templately_connect_data');
			$_api_key       = $this->options->get_option('_templately_api_key');

			if ( $user_id === false ) {
				$user_id = get_current_user_id();

				$_api_key       = $this->options->get('api_key', false, $user_id );
				$user_profile   = $this->options->get('connect_data', false, $user_id );
				$cloud_activity = $this->options->get('cloud_last_activity', false, $user_id );
			}


			if( ! empty( $_api_key ) ) {
				// SET API
				$this->options->set('api_key', $_api_key, $user_id );
				$this->options->set('cloud_activity', $cloud_activity, $user_id );

				$_favourites = [];
				if( isset( $user_profile['favourites'] ) ) {
					$_favourites = $user_profile['favourites'];
					unset($user_profile['favourites']);
				}

				$this->options->set('user', $user_profile, $user_id );
				$this->options->set('favourites', $_favourites, $user_id );
			}
		}

		/**
		 * Migration for v2.2.10 to v2.2.11
		 */
		if( TEMPLATELY_VERSION !== $_old_version && \version_compare($_old_version, '2.2.10', '<=') ) {
			Profile::get_instance()->sync();
		}

		$this->cleanup_gutenberg_elementor_meta();

		$this->options->update_option( '_templately_migrate', TEMPLATELY_VERSION );
	}

	/**
	 * One-time cleanup: a bug mapped EVERY `templately_library` template to an Elementor
	 * document type regardless of platform (the mapping gated on the CPT, not the
	 * template's platform). Elementor therefore adopted GUTENBERG templates as Elementor
	 * documents and persisted Elementor meta onto them (`_elementor_edit_mode=builder`,
	 * `_elementor_template_type`, ...), so they reported `is_built_with_elementor() === true`
	 * and surfaced Elementor's "Edit with Elementor" UI for a Gutenberg post. The mapping is
	 * now platform-gated (ThemeBuilder::modify_template_type); strip the already-persisted
	 * Elementor meta from Gutenberg-platform templates so they are no longer treated as
	 * Elementor documents. Runs once (own flag), independent of the version gates above.
	 */
	private function cleanup_gutenberg_elementor_meta(){
		$flag = '_templately_cleaned_gutenberg_elementor_meta';

		if ( $this->options->get_option( $flag ) ) {
			return;
		}

		global $wpdb;

		$post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value = %s",
			'templately_library',
			'_templately_template_platform',
			'gutenberg'
		) );

		// Pure-Elementor document meta that must not live on a Gutenberg template.
		$meta_keys = [
			'_elementor_edit_mode',
			'_elementor_template_type',
			'_elementor_data',
			'_elementor_version',
			'_elementor_page_settings',
			'_elementor_css',
		];

		foreach ( (array) $post_ids as $post_id ) {
			foreach ( $meta_keys as $meta_key ) {
				delete_post_meta( (int) $post_id, $meta_key );
			}
		}

		$this->options->update_option( $flag, time() );
	}

}
