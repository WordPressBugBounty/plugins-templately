<?php
/**
 * Elementor Kit & Settings module (spec 031-elementor-kit-settings).
 *
 * PHP-only utility module, no REST routes, no JS — matches the
 * modules/ai-content-merger/ shape. Two static utilities, each with genuinely
 * independent, multi-spec consumers (the exact reason this warrants its own
 * module rather than staying embedded in full-site-import or settings):
 *
 * - ElementorSettingsMerger: resolves `__globals__` references in element
 *   settings to concrete kit values. Relocated from
 *   modules/full-site-import/Utils/ElementorSettingsMerger.php — behavior
 *   byte-identical. Consumed by modules/full-site-import/ (FSI pipeline) AND
 *   includes/Core/Platform/Elementor.php (single-template import, not yet
 *   extracted — future spec 032's territory).
 * - KitApplier: writes customizer-output color/font assignments into the
 *   active Elementor kit. Surgically extracted from
 *   modules/settings/REST/Settings.php::update_elementor_settings() —
 *   behavior byte-identical; the REST route/param-parsing stays in
 *   modules/settings/, only the "write into the kit" logic moved.
 *
 * SCOPE NOTE: the kit-import runner logic (backup-active-kit-id,
 * create-new-kit-and-activate, apply theme_style) lives inline inside
 * modules/full-site-import/Runners/ElementorContent.php's import() method,
 * interleaved with that runner's OTHER job (Elementor page/post content
 * import — same class, same pipeline slot as GutenbergContent). It was NOT
 * extracted: the FSI Loop trait derives per-item resume keys from the
 * runner's `Class::method` call identity (see
 * modules/full-site-import/Runners/CLAUDE.md), so relocating the runner
 * class itself would change that identity and could strand an in-flight
 * import's resume position across a plugin update. The 3 private kit-manager
 * fallback/compat helper methods on that class
 * (get_kit_with_fallback/create_new_kit_with_fallback/
 * create_default_kit_with_fallback) have no consumer outside that one file,
 * so extracting them into this module would add indirection without a
 * reuse benefit. Manual kit-revert-on-failure lives in
 * modules/import-revert/RevertController.php as one line within a
 * GENERIC options-restore loop covering the whole FSI revert surface (not
 * kit-specific code) — also stays put.
 *
 * @package Templately
 */

namespace Templately\Modules\ElementorKitSettings;

use Templately\Core\Module_Base;

class Module extends Module_Base {

	public function get_name(): string {
		return 'elementor-kit-settings';
	}
}
