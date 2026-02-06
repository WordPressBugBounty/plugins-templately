<?php

namespace Templately\Core\Importer\Utils;

use Exception;
use Templately\Core\Importer\FullSiteImport;
use Templately\Core\Importer\WPImport;
use Templately\Utils\Base;
use Templately\Utils\Helper;

class Utils extends Base {


	/**
	 * @throws Exception
	 */
	public static function read_json_file( $path ) {
		if ( ! file_exists( $path ) ) {
			throw new Exception( __( 'JSON file not exists. ' . basename( $path ), 'templately' ) );
		}

		$file_content = self::file_get_contents( $path );

		return $file_content ? json_decode( $file_content, true ) : [];
	}

	/**
	 * @param $file
	 * @param mixed ...$args
	 *
	 * @return false|string
	 */
	public static function file_get_contents( $file, ...$args ) {
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return false;
		}

		return file_get_contents( $file, ...$args );
	}

	public static function get_builtin_wp_post_types(): array {
		$post_type_args = [
			'show_in_nav_menus' => true,
			'public'            => true
		];
		$_post_types    = get_post_types( $post_type_args, 'objects' );

		return array_merge( array_keys( $_post_types ), [ 'nav_menu_item', 'wp_navigation' ] );
	}

	public static function map_old_new_post_ids( array $imported_data ) {
		$result = [];

		$result += $imported_data['templates']['succeed'] ?? [];

		if ( isset( $imported_data['content'] ) ) {
			foreach ( $imported_data['content'] as $post_type ) {
				$result += $post_type['succeed'] ?? [];
			}
		}

		if ( isset( $imported_data['wp-content'] ) ) {
			foreach ( $imported_data['wp-content'] as $post_type ) {
				$result += $post_type['succeed'] ?? [];
			}
		}

		// add attachments data
		if ( !empty( $imported_data['attachments']['succeed'] ) ) {
			$result += $imported_data['attachments']['succeed'] ?? [];
		}


		return $result;
	}

	public static function map_old_new_term_ids( array $imported_data ) {
		$result = [];

		if ( isset( $imported_data['terms'] ) ) {
			foreach ( $imported_data['terms'] as $post_type ) {
				$result += $post_type['succeed'] ?? [];
			}
		}

		return $result;
	}

	public static function map_old_new_term_ids_el( array $imported_data ): array {
		$result = [];

		if ( ! isset( $imported_data['taxonomies'] ) ) {
			return $result;
		}

		foreach ( $imported_data['taxonomies'] as $post_type_taxonomies ) {
			foreach ( $post_type_taxonomies as $taxonomy ) {
				foreach ( $taxonomy as $term ) {
					$result[ $term['old_id'] ] = $term['new_id'];
				}
			}
		}

		return $result;
	}

	/**
	 * @param string $platform
	 *
	 * @return ImportHelper
	 */
	public static function get_json_helper( string $platform ) {
		return $platform === 'elementor' ? new ElementorHelper() : new GutenbergHelper();
	}

	public static function get_backup_options() {
		global $wpdb;

		$prefix = '__templately_';
		$table_name = $wpdb->options; // Assuming default options table name

		$sql = "SELECT option_name, option_value FROM {$table_name} WHERE option_name LIKE %s";
		$prepared_sql = $wpdb->prepare($sql, array("$prefix%")); // Escape wildcard for security

		$results = $wpdb->get_results($prepared_sql);

		$templately_options = array();
		foreach ($results as $row) {
			$name = str_replace($prefix, '', $row->option_name);
			$templately_options[$name] = maybe_unserialize($row->option_value);
		}

		return $templately_options;
	}

	public static function backup_option_value($key, $autoload = 'no') {
		$old_value = get_option($key);
		if ($old_value) {
			update_option("__templately_$key", $old_value, $autoload);
		}
		else {
			add_option("__templately_$key", $old_value, '', $autoload);
		}
	}

	public static function update_option($key, $value, $autoload = 'no') {
		self::backup_option_value($key, $autoload);
		return update_option($key, $value, $autoload);
	}

	public static function import_page_settings( $id, $settings ) {
		$extra_settings = [
			'page_on_front' => [
				'show_on_front' => 'page'
			]
		];
		if ( isset( $settings['page_for_posts'] ) && $settings['page_for_posts'] ) {
			self::update_option( 'page_for_posts', $id );
		}
		if ( isset( $settings['show_on_front'] ) && $settings['show_on_front'] ) {
			self::update_option( 'page_on_front', $id );
			self::update_option( 'show_on_front', 'page' );
		}
		if ( ! empty( $settings['page_settings'] ) ) {
			foreach ( $settings['page_settings'] as $option_name => $val ) {
				$__val = $id;
				if($option_name === 'fluent_cart_store_settings'){
					$__val = $val;
				}
				self::update_option( $option_name, $__val );
				if ( array_key_exists( $option_name, $extra_settings ) ) {
					foreach ( $extra_settings[ $option_name ] as $name => $value ) {
						self::update_option( $name, $value );
					}
				}
			}
		}
	}

	public static function upload_logo($url, $session_id) {
		if(empty($url)) {
			return ['error' => __('URL is empty', 'templately')];
		}

		// Validate URL and ensure scheme is present
		if ( ! wp_http_validate_url( $url ) || !parse_url( $url, PHP_URL_SCHEME ) ) {
			return ['error' => __('Invalid URL', 'templately')];
		}

		$post_data     = self::prepare_post_data($url);
		$wp_importer   = new WPImport( null, ['fetch_attachments' => true, 'session_id' => $session_id] );
		$attachment_id = $wp_importer->process_attachment($post_data, $url);

		if(is_wp_error($attachment_id)){
			return ['error' => $attachment_id->get_error_message()];
		}

		return [
			'id'  => (int) $attachment_id,
			'url' => esc_url_raw(wp_get_attachment_url($attachment_id)),
		];
	}

	/**
	 * Upload base64 encoded image to media library
	 *
	 * @param string $base64 Base64 encoded image data (with or without data URI scheme)
	 * @param string $session_id Session ID for tracking (reserved for future use)
	 * @return array Array with 'id' and 'url' on success, or ['error' => message] on failure
	 */
	public static function upload_logo_base64($base64, $session_id = null) {
		if(empty($base64)) {
			return ['error' => __('Base64 is empty', 'templately')];
		}

		// Upload the base64 image
		$attachment_id = self::upload_base64_image($base64);

		if(is_wp_error($attachment_id)){
			return ['error' => $attachment_id->get_error_message()];
		}

		return [
			'id'  => (int) $attachment_id,
			'url' => esc_url_raw(wp_get_attachment_url($attachment_id)),
		];
	}

	/**
	 * Upload base64 encoded image without dependency on prepare_post_data
	 * Handles MIME type detection and proper file extension assignment
	 *
	 * @param string $base64 Base64 encoded image data (with or without data URI scheme)
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure
	 */
	public static function upload_base64_image($base64) {
		// Decode base64 string
		$decoded_image = base64_decode($base64, true);
		if ($decoded_image === false) {
			return new \WP_Error('invalid_base64', __('Invalid base64 data.', 'templately'));
		}

		// Detect MIME type from decoded image data
		$mime_type = self::detect_mime_type_from_data($decoded_image);
		if (empty($mime_type)) {
			return new \WP_Error('unknown_mime_type', __('Unable to determine image MIME type.', 'templately'));
		}

		// Get file extension from MIME type
		$extension = self::get_file_extension_by_mime_type($mime_type);
		if (empty($extension)) {
			return new \WP_Error('unsupported_mime_type', __('Unsupported image MIME type.', 'templately'));
		}

		// Generate unique filename
		$filename = 'templately-logo-' . \wp_generate_uuid4() . '.' . $extension;

		// Get upload directory
		$upload_dir = \wp_upload_dir();
		if (!$upload_dir['error']) {
			$upload_path = $upload_dir['path'] . '/' . $filename;
		} else {
			return new \WP_Error('upload_dir_error', __('Unable to access upload directory.', 'templately'));
		}

		// Write decoded image to file
		if (file_put_contents($upload_path, $decoded_image) === false) {
			return new \WP_Error('upload_error', __('Error uploading image.', 'templately'));
		}

		// Create attachment post
		$attachment_data = [
			'post_mime_type' => $mime_type,
			'post_title'     => \sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];

		$attachment_id = \wp_insert_attachment($attachment_data, $upload_path);
		if (is_wp_error($attachment_id)) {
			return $attachment_id;
		}

		// Ensure WordPress image functions are available
		// These functions are defined in wp-admin/includes/image.php which is not always loaded
		if (!function_exists('wp_generate_attachment_metadata')) {
			require_once(ABSPATH . 'wp-admin/includes/image.php');
		}

		// Generate and update attachment metadata
		$metadata = \wp_generate_attachment_metadata($attachment_id, $upload_path);
		\wp_update_attachment_metadata($attachment_id, $metadata);

		return $attachment_id;
	}

	/**
	 * Detect MIME type from image data
	 * Uses finfo_buffer if available, otherwise falls back to getimagesizefromstring
	 *
	 * @param string $image_data Raw image data
	 * @return string|null MIME type or null if unable to detect
	 */
	private static function detect_mime_type_from_data($image_data) {
		// Try using finfo_buffer first (most reliable)
		if (function_exists('finfo_buffer')) {
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			if ($finfo) {
				$mime_type = finfo_buffer($finfo, $image_data);
				finfo_close($finfo);
				if ($mime_type && strpos($mime_type, 'image/') === 0) {
					return $mime_type;
				}
			}
		}

		// Fallback: use getimagesizefromstring
		if (function_exists('getimagesizefromstring')) {
			$image_info = @getimagesizefromstring($image_data);
			if ($image_info && isset($image_info['mime'])) {
				return $image_info['mime'];
			}
		}

		return null;
	}

	/**
	 * Get file extension by MIME type
	 * Uses WordPress built-in functions for MIME type to extension conversion
	 *
	 * @since 3.4.5
	 * @param string $mime_type MIME type (e.g., 'image/png')
	 * @return string|null File extension without dot, or null if not found
	 */
	private static function get_file_extension_by_mime_type($mime_type) {
		// Use WordPress core function if available (WordPress 5.8.1+)
		// wp_get_default_extension_for_mime_type() returns the default file extension for a given MIME type
		if (function_exists('wp_get_default_extension_for_mime_type')) {
			return \wp_get_default_extension_for_mime_type($mime_type);
		}

		// Fallback for WordPress < 5.8.1
		// Use wp_get_mime_types() which returns array with extensions as keys and MIME types as values
		// Example: ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', ...]
		$wp_mime_types = \wp_get_mime_types();

		// Flip the array to get MIME type as key and extensions as value
		$mime_map = array_flip($wp_mime_types);

		if (isset($mime_map[$mime_type])) {
			$extensions = $mime_map[$mime_type];
			// Get first extension if multiple are available (e.g., 'jpg|jpeg|jpe' -> 'jpg')
			return strtok($extensions, '|');
		}

		return null;
	}

    /**
     * Inserts a template into the Gutenberg editor.
     *
     * @param mixed $data
     * @param int $postId
     * @return array
     */
    public static function import_and_replace_attachments($content, $postId = 0) {
        // Instantiate GutenbergHelper
        $helper = new GutenbergHelper();

		$data = [
			'content' => $content,
		];

        // Organize URLs from the content
        $organizedUrls = $helper->parse_images($data['content']);
		if(empty($organizedUrls)){
			return $content;
		}

        // Define template settings
        $template_settings = [
            'post_id'       => $postId,
            '__attachments' => $organizedUrls,
        ];

        // Map post IDs and disable logging
        $helper->map_post_ids[$postId] = $postId;
        $helper->shouldLog = false;

        // Prepare the helper with the data and settings
        $helper->prepare($data, $template_settings);

        // Update the content in the data array
        $content = wp_unslash($helper->get_content());

        return $content;
    }

	public static function prepare_post_data($image_url, $post_parent = null, $logger = null) {
		$filetype = wp_check_filetype(basename($image_url));
		if (!$filetype['type']) {
			if(is_callable($logger)){
				// call the logger function
				call_user_func($logger, 'prepare', 'Error: Unable to determine the file type.', -1, 'eventLog');
			}
			return null;
		}

		$post_data = array(
			'post_title'     => basename($image_url),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_mime_type' => $filetype['type'],
			'guid'           => $image_url,
		);

		if($post_parent){
			$post_data['post_parent'] = $post_parent; // Set the parent post
		}

		if (preg_match('%wp-content/uploads/([0-9]{4}/[0-9]{2})%', $image_url, $matches)) {
			$post_data['upload_date'] = $matches[1];
		}
		else{
			$post_data['upload_date'] = date('Y/m');
		}

		return $post_data;
	}

    // Static version of get_session_data
    public static function get_all_session_data(): array {
        $data = get_option(FullSiteImport::SESSION_OPTION_KEY, []);
        return $data ?? [];
    }

    // Static version of get_session_data
    public static function get_session_data($session_id): array {
        $data = get_option(FullSiteImport::SESSION_OPTION_KEY, []);
        return $data[$session_id] ?? [];
    }

    // Static version of update_session_data
    public static function update_session_data($session_id, $data): bool {
        $old_data = get_option(FullSiteImport::SESSION_OPTION_KEY, []);
        return update_option(FullSiteImport::SESSION_OPTION_KEY, Helper::recursive_wp_parse_args([$session_id => $data], $old_data));
    }

    // Delete specific session data by ID
    public static function delete_session_data($session_id): bool {
        $all_data = get_option(FullSiteImport::SESSION_OPTION_KEY, []);

        if (!isset($all_data[$session_id])) {
            return false; // Session ID doesn't exist
        }

        unset($all_data[$session_id]);
        return update_option(FullSiteImport::SESSION_OPTION_KEY, $all_data);
    }

	public static function get_session_id(){
		$session_id = null;
		if(!empty($_REQUEST['session_id'])){
			$session_id = sanitize_text_field($_REQUEST['session_id']);
		}
		return $session_id;
	}

	public static function get_session_data_by_id(): array {
		if($session_id = self::get_session_id()){
			return self::get_session_data($session_id);
		}
		throw new Exception(__('Invalid Session ID.', 'templately'));
	}

	public static function update_session_data_by_id($data): bool {
		if($session_id = self::get_session_id()){
			return self::update_session_data($session_id, $data);
		}
		throw new Exception(__('Invalid Session ID.', 'templately'));
	}



	/**
	 * Clean up directory using RecursiveIteratorIterator approach
	 * This method handles directory cleanup with proper validation
	 *
	 * @param string $dir_path The directory path to clean up
	 * @return bool True on success, false on failure
	 */
	public static function cleanup_directory($dir_path) {
		if (empty($dir_path) || !file_exists($dir_path) || !is_dir($dir_path)) {
			return false;
		}

		try {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($dir_path, \RecursiveDirectoryIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ($files as $fileinfo) {
				$todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
				$todo($fileinfo->getRealPath());
			}

			rmdir($dir_path);
			return true;
		} catch (Exception $e) {
			return false;
		}
	}









	/**
	 * Clean session data by pack ID, keeping only the current session
	 * Removes all session entries with the same pack_id except the current session
	 *
	 * @param string $pack_id The pack ID to match for cleanup
	 * @param string $current_session_id The current session ID to preserve
	 * @return array Array of removed session IDs
	 */
	public static function clean_session_data_by_pack_id($pack_id, $current_session_id) {
		if (empty($pack_id) || empty($current_session_id)) {
			return [];
		}

		$all_session_data = self::get_all_session_data();
		$removed_session_ids = [];


		foreach ($all_session_data as $session_id => $session_data) {
			// Skip the current session
			if ($session_id === $current_session_id) {
				continue;
			}

			// Remove sessions that have the same pack_id
			if (isset($session_data['id']) && $session_data['id'] === $pack_id) {
				unset($all_session_data[$session_id]);
				$removed_session_ids[] = $session_id;
			}
		}

		// Update the option with cleaned data if any sessions were removed
		if (!empty($removed_session_ids)) {
			update_option(FullSiteImport::SESSION_OPTION_KEY, $all_session_data);
		}

		return $removed_session_ids;
	}



}
