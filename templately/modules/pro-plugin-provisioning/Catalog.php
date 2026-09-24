<?php
/**
 * The closed set of pro plugins Templately may obtain on a user's behalf.
 *
 * Two entries, hard-coded. This is deliberately NOT configurable and deliberately NOT
 * something the cloud can widen: the question it answers is "which plugin archives may we
 * download from a third party and execute on this site", and a dependency descriptor that
 * merely CLAIMS to be provisionable is not evidence. A compromised or spoofed dependency
 * response could otherwise induce a download-and-activate of anything.
 *
 * The client-side twin lives in `assets/js/isProvisionable.ts` and lists the same
 * plugin files. `tests/unit/test-CatalogParity.php` fails if the two drift, because a
 * drift means the UI offers a checkbox for something the backend will refuse — the
 * confusing half-state the fallback rules exist to avoid.
 *
 * @package Templately\Modules\ProPluginProvisioning
 */

namespace Templately\Modules\ProPluginProvisioning;

class Catalog {

	/**
	 * Provisionable plugins, keyed by their WordPress plugin file.
	 *
	 * `platform` is the value the download service's `platform` parameter takes, and it is
	 * read from HERE rather than from the import request — the request's platform describes
	 * the pack, this describes the plugin, and only the second is safe to put in a URL.
	 *
	 * `free_slug` records which free edition must be present first. Ordering is enforced by
	 * the full-site dependency runner's own sort, not by this table; the column exists so
	 * the relationship is written down once instead of inferred from a sort array.
	 */
	const PLUGINS = [
		'essential-addons-elementor/essential_adons_elementor.php' => [
			'slug'        => 'essential-addons-elementor',
			'plugin_file' => 'essential-addons-elementor/essential_adons_elementor.php',
			'platform'    => 'elementor',
			'name'        => 'Essential Addons Pro',
			'main_file'   => 'essential_adons_elementor.php',
			'free_slug'   => 'essential-addons-for-elementor-lite',
		],
		'essential-blocks-pro/essential-blocks-pro.php'            => [
			'slug'        => 'essential-blocks-pro',
			'plugin_file' => 'essential-blocks-pro/essential-blocks-pro.php',
			'platform'    => 'gutenberg',
			'name'        => 'Essential Blocks Pro',
			'main_file'   => 'essential-blocks-pro.php',
			'free_slug'   => 'essential-blocks',
		],
	];

	/**
	 * Resolve a dependency descriptor to its catalog entry, or null.
	 *
	 * Matches on `plugin_file` FIRST and `slug` only as a fallback: a slug can be reused by
	 * an unrelated plugin, a plugin file cannot. A descriptor whose slug matches but whose
	 * plugin file does not is NOT a match — that combination is exactly what a spoofed
	 * entry would look like.
	 *
	 * @param array $dependency Dependency descriptor (`plugin_file`, `slug`, `plugin_original_slug`).
	 * @return array|null
	 */
	public static function find( array $dependency ) {
		$plugin_file = isset( $dependency['plugin_file'] ) && is_string( $dependency['plugin_file'] )
			? $dependency['plugin_file']
			: '';

		if ( '' !== $plugin_file && isset( self::PLUGINS[ $plugin_file ] ) ) {
			return self::PLUGINS[ $plugin_file ];
		}

		// A descriptor that names a plugin file we do not know is not a match, even if its
		// slug is one of ours — the file is the identity.
		if ( '' !== $plugin_file ) {
			return null;
		}

		foreach ( [ 'slug', 'plugin_original_slug' ] as $key ) {
			$slug = isset( $dependency[ $key ] ) && is_string( $dependency[ $key ] ) ? $dependency[ $key ] : '';

			if ( '' === $slug ) {
				continue;
			}

			foreach ( self::PLUGINS as $entry ) {
				if ( $entry['slug'] === $slug ) {
					return $entry;
				}
			}
		}

		return null;
	}

	/**
	 * Whether this dependency is one Templately may obtain.
	 *
	 * @param array $dependency Dependency descriptor.
	 * @return bool
	 */
	public static function is_provisionable( array $dependency ): bool {
		return null !== self::find( $dependency );
	}

	/**
	 * Every provisionable plugin file, for parity checks and tests.
	 *
	 * @return string[]
	 */
	public static function plugin_files(): array {
		return array_keys( self::PLUGINS );
	}
}
