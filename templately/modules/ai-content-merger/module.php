<?php
/**
 * AI-content-merger module (spec 022-ai-content-merger).
 *
 * Platform-aware AI content merging: the AIContentHelper class (flatten-by-id, unicode
 * normalization, Elementor/Gutenberg dot-notation content merging, HTML class-based
 * replacement). Relocated from includes/Core/Importer/Utils/AIContentHelper.php.
 *
 * PHP-only, no REST routes, no JS. Per spec 022 FR-011 this is a STANDALONE class of pure
 * static merge logic with ZERO cross-module dependencies — the public entry point is the
 * static merge($payload, $template, $platform) dispatcher, consumed by FSI's
 * Runners/Finalizer. The session/path resolution + file IO that used to live here as
 * host-state instance methods (generateAiFilePaths, isAiContent, processAiContent,
 * writeDebugFile, …) is now the consumer's responsibility, breaking the former
 * full-site-import ↔ ai-content-merger dependency cycle.
 *
 * @package Templately
 */

namespace Templately\Modules\AiContentMerger;

use Templately\Core\Module_Base;

class Module extends Module_Base {

	public function get_name(): string {
		return 'ai-content-merger';
	}
}
