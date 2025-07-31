<?php

namespace Templately\Core\Importer\Utils;

/**
 * AI Content Helper Trait
 *
 * Handles AI content processing functionality including file validation,
 * template flattening, element updating, and content merging.
 *
 * This trait can be used by classes that need AI content processing capabilities
 * without having to pass many variables as parameters.
 */
trait AIContentHelper {
	public $htmlSources = [
		'testimonial',
		'feature-list',
		'notice',
		'pricing-table',
		'typing-text',
		'interactive-promo',
		'call-to-action'
	];

	/**
	 * Check if an AI file exists and is not skipped
	 *
	 * @param string $ai_file_path Path to the AI file
	 * @return bool True if AI file exists and is not skipped, false otherwise
	 */
	protected function hasAiFile($ai_file_path) {
		if (file_exists($ai_file_path)) {
			$ai_file = Utils::read_json_file($ai_file_path);
			if (isset($ai_file['isSkipped']) && $ai_file['isSkipped']) {
				return false;
			}
			return true;
		}
		return false;
	}

	/**
	 * Generate file paths for AI content processing
	 *
	 * @param string $old_template_id The template ID
	 * @return array Array containing paths for original, AI, and previous AI files
	 */
	protected function generateAiFilePaths($old_template_id) {
		$path = $this->dir_path . $this->type . DIRECTORY_SEPARATOR;
		$prv_path = $this->prv_dir . $this->process_id . DIRECTORY_SEPARATOR . $this->type . DIRECTORY_SEPARATOR;

		if (!empty($this->sub_type)) {
			$path .= $this->sub_type . DIRECTORY_SEPARATOR;
			$prv_path .= $this->sub_type . DIRECTORY_SEPARATOR;
		}

		return [
			'original_file' => $path . "{$old_template_id}.json",
			'ai_file_path'  => $prv_path . "{$old_template_id}.ai.json",
		];
	}

	/**
	 * Check if content should be processed as AI content
	 *
	 * @param string $old_template_id The template ID to check
	 * @return bool True if this is AI content, false otherwise
	 */
	public function isAiContent($old_template_id) {
		// Generate file paths
		$paths = $this->generateAiFilePaths($old_template_id);

		// Check if this template ID is in the AI page IDs list
		$is_ai_template = $this->is_ai_content($old_template_id);

		// Check if AI files exist
		$has_ai_file = $this->hasAiFile($paths['ai_file_path']);

		return $is_ai_template || $has_ai_file;
	}

	/**
	 * Check if an AI file exists and is marked as skipped
	 *
	 * @param string $old_template_id The template ID to check
	 * @return bool True if AI file exists and is skipped, false otherwise
	 */
	public function isAiFileSkipped($old_template_id) {
		// Generate file paths
		$paths = $this->generateAiFilePaths($old_template_id);
		$ai_file_path = $paths['ai_file_path'];

		// Check if file exists
		if (file_exists($ai_file_path)) {
			$ai_file = Utils::read_json_file($ai_file_path);
			// Return true if the file exists AND is marked as skipped
			return isset($ai_file['isSkipped']) && $ai_file['isSkipped'];
		}

		// Return false if file doesn't exist
		return false;
	}

	/**
	 * Recursively flatten a nested array by extracting elements with 'contents' and using their ID as key
	 *
	 * @param array $array The array to flatten
	 * @param array $flat Reference to the flattened array
	 * @return array The flattened array
	 */
	protected function flattenById($array, &$flat = []) {
		foreach ($array as $key => $value) {
			if (is_array($value)) {
				// If this is an element with 'widgetType' and 'contents', use its parent key as ID
				if (isset($value['widgetType']) && isset($value['contents'])) {
					$flat[$key] = $value;
				}
				// Recurse into children
				$this->flattenById($value, $flat);
			}
		}
		return $flat;
	}



	/**
	 * Set a value in a nested array using a dot notation path
	 *
	 * @param array $array Reference to the array to modify
	 * @param array $path The path as an array of keys
	 * @param mixed $value The value to set
	 */
	protected function setNestedValue(&$array, $path, $value) {
		$key = array_shift($path);

		if (empty($path)) {
			// We've reached the final key, set the value
			$array[$key] = $value;
		} else {
			// Initialize the nested array if it doesn't exist
			if (!isset($array[$key]) || !is_array($array[$key])) {
				$array[$key] = [];
			}

			// Continue recursively
			$this->setNestedValue($array[$key], $path, $value);
		}
	}

	/**
	 * Process AI content by merging it with the original template
	 *
	 * @param string $old_template_id The template ID to process
	 * @return array Array containing the processed template and whether it's AI content
	 */
	public function processAiContent($old_template_id) {
		// Generate file paths
		$paths = $this->generateAiFilePaths($old_template_id);
		$original_file = $paths['original_file'];
		$ai_file = $paths['ai_file_path'];

		$file = $original_file;
		$isAi = false;

		// Check for AI file
		if ($this->hasAiFile($ai_file)) {
			$file = $ai_file;
			$isAi = true;
		}

		// Read the template JSON
		$template_json = Utils::read_json_file($file);

		if ($isAi) {
			if($this->platform === 'elementor'){
				$template_json = $this->mergeAiContentWithOriginal($template_json, $original_file, $file);
			}
			else if($this->platform === 'gutenberg'){
				$template_json = $this->mergeAiContentWithOriginalGutenberg($template_json, $original_file, $file);
			}
		}

		return [
			'template_json' => $template_json,
			'is_ai' => $isAi,
			'file_used' => $file
		];
	}

	/**
	 * Merge AI content with the original template
	 *
	 * @param array $ai_template_json The AI template JSON
	 * @param string $original_file Path to the original template file
	 * @param string $ai_file Path to the AI file being processed
	 * @return array The merged template JSON
	 */
	protected function mergeAiContentWithOriginal($ai_template_json, $original_file, $ai_file) {
		$original_template_json = Utils::read_json_file($original_file);

		// 1. Flatten the AI template
		$flat = $this->flattenById($ai_template_json);
		$keys = array_keys($flat);

		// 2. Loop through original content only once and update elements directly
		$this->updateElementorContentRecursively($flat, $keys, $original_template_json['content']);

		// Save the processed content for debugging (optional)
		$this->writeDebugFile($original_file, $original_template_json, 'ao');

		return $original_template_json;
	}

	/**
	 * Update Elementor content recursively by looping through original content only once
	 *
	 * @param array $flat The flattened AI content array
	 * @param array $keys Array of element IDs from the flat array
	 * @param array $content Reference to the original content to update
	 */
	protected function updateElementorContentRecursively($flat, array $keys, &$content) {
		if (!is_array($content)) {
			return;
		}

		// Check if this element has an ID and needs updating
		if (isset($content['id']) && in_array($content['id'], $keys)) {
			$element_id = $content['id'];
			$element = $flat[$element_id];

			if (isset($element['contents'])) {
				// Update settings based on contents
				foreach ($element['contents'] as $item) {
					if (isset($item['attribute'], $item['content'])) {
						// Normalize content: unescape closing tags (remove backslash before </)
						$content_value = is_string($item['content'])
							? str_replace(['<\/', '<\\/'], '</', $item['content'])
							: $item['content'];

						// Support for dot notation in attribute paths
						if (strpos($item['attribute'], '.') !== false) {
							$path = explode('.', $item['attribute']);
							$this->setNestedValue($content['settings'], $path, $content_value);
						} else {
							$content['settings'][$item['attribute']] = $content_value;
						}
					}
				}
			}
		}

		// Recurse through elements array
		if (isset($content['elements']) && is_array($content['elements'])) {
			foreach ($content['elements'] as &$element) {
				$this->updateElementorContentRecursively($flat, $keys, $element);
			}
		}

		// Recurse through all other array elements
		foreach ($content as &$value) {
			if (is_array($value)) {
				$this->updateElementorContentRecursively($flat, $keys, $value);
			}
		}
	}

	/**
	 * Merge AI content with the original Gutenberg template using advanced content replacement
	 *
	 * @param array $ai_template_json The AI template JSON
	 * @param string $original_file Path to the original template file
	 * @param string $ai_file Path to the AI file being processed
	 * @return array The merged template JSON
	 */
	protected function mergeAiContentWithOriginalGutenberg($ai_template_json, $original_file, $ai_file) {
		$original_template_json = Utils::read_json_file($original_file);
		if (empty($original_template_json['content'])) {
			return $original_template_json;
		}

		// 1. Flatten the AI template by block ID (for blocks with 'contents')
		$flat = [];
		$this->flattenGutenbergById($ai_template_json, $flat);
		$generated = $flat;
		$keys = array_keys($generated);

		// 2. Parse the original Gutenberg content
		$blocks = parse_blocks($original_template_json['content']);
		// 5. Save the processed content for debugging (optional)
		$this->writeDebugFile($original_file, $blocks, 'og');

		// 3. Replace content recursively using advanced replacer logic
		$blocks = $this->replaceGutenbergContentRecursively($generated, $keys, $blocks);

		// 4. Serialize the updated blocks back to content
		$original_template_json['content'] = serialize_blocks($blocks);

		// 5. Save the processed content for debugging (optional)
		$this->writeDebugFile($original_file, $blocks, 'ao');

		return $original_template_json;
	}

	/**
	 * Replace content recursively in Gutenberg blocks (ported from GutenbergContentReplacer)
	 */
	protected function replaceGutenbergContentRecursively($generated, array $keys, &$blocks) {
		foreach ($blocks as &$block) {
			if (!empty($block['attrs']['blockId'])) {
				$blockId = $block['attrs']['blockId'];
				if (in_array($blockId, $keys)) {
					$blockData = $generated[$blockId];
					$block_name = $this->cleanBlockName( $block['blockName'] );

					// Store old content BEFORE updating attributes
					$oldContentMap = [];
					if (!empty($blockData['contents']) && !in_array($block_name, $this->htmlSources)) {
						foreach ($blockData['contents'] as $content) {
							$attribute = $content['attribute'];
							$oldContent = $this->getNestedGutenbergAttribute($block['attrs'], $attribute);
							if ($oldContent !== null) {
								$oldContentMap[$attribute] = $oldContent;
							}
						}
					}

					// Replace content in attributes
					if (!empty($blockData['contents']) && !in_array($block_name, $this->htmlSources)) {
						foreach ($blockData['contents'] as $content) {
							$attribute = $content['attribute'];
							$newContent = $content['content'];
							$this->setNestedGutenbergAttribute($block['attrs'], $attribute, $newContent);
						}
					}

					// Replace content in innerHTML and innerContent using old content
					if (!empty($block['innerHTML']) || !empty($block['innerContent'])) {
						$this->replaceInGutenbergHtmlContent($block, $blockData, $oldContentMap);
					}

					if(in_array($block_name, $this->htmlSources)){
						if (!empty($blockData['contents'])) {
							if (!empty($block['innerHTML'])) {
								$block['innerHTML'] = $this->replaceContentByClassName($block['innerHTML'], $blockData['contents']);
							}
							if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
								foreach ($block['innerContent'] as &$content) {
									if (is_string($content)) {
										$content = $this->replaceContentByClassName($content, $blockData['contents']);
									}
								}
							}
						}
					}
					if($block["blockName"] === 'essential-blocks/accordion'){
						$block_inner_block_ids = array_map(function($innerBlock) {
							return $innerBlock["attrs"]["blockId"] ?? null;
						}, $block["innerBlocks"]);
						$_generated = array_fill_keys($block_inner_block_ids, ['contents' => $blockData['contents']]);

						if(isset($block["innerBlocks"][0]["attrs"]["accordionLists"]) && count($block["innerBlocks"][0]["attrs"]["accordionLists"]) > 1){
							$block["innerBlocks"] = $this->replaceGutenbergContentRecursively($_generated, $block_inner_block_ids, $block['innerBlocks']);
						}
						else {
							// $block["attrs"]["accordionLists"][0]["id"]
							$attrAccordionLists = $block["attrs"]["accordionLists"];
							foreach ($block["innerBlocks"] as $key => $accordion) {
								foreach($accordion["attrs"]["accordionLists"] as $accordionKey => $accordionList){
									// $accordionList["id"]
									// search $block["attrs"]["accordionLists"] by $accordionList["id"] and replace $accordionList with searched one
									$ids = array_column($attrAccordionLists, 'id');
									$foundIndex = array_search($accordionList["id"], $ids);
									if ($foundIndex !== false) {
										$block["innerBlocks"][$key]["attrs"]["accordionLists"][$accordionKey] = $attrAccordionLists[$foundIndex];
									}
								}
							}
						}
					}
				}

				// Process nested blocks recursively
				if (!empty($block['innerBlocks'])) {
					$block['innerBlocks'] = $this->replaceGutenbergContentRecursively($generated, $keys, $block['innerBlocks']);
				}
			}
		}
		return $blocks;
	}

	/**
	 * Set nested attribute value using dot notation (ported from GutenbergContentReplacer)
	 */
	protected function setNestedGutenbergAttribute(&$attrs, $path, $value) {
		$keys = explode('.', $path);
		$current = &$attrs;
		for ($i = 0; $i < count($keys) - 1; $i++) {
			$key = $keys[$i];
			if (!isset($current[$key])) {
				$current[$key] = [];
			}
			$current = &$current[$key];
		}
		$finalKey = end($keys);
		$current[$finalKey] = $value;
	}

	/**
	 * Get nested attribute value using dot notation (ported from GutenbergContentReplacer)
	 */
	protected function getNestedGutenbergAttribute($attrs, $path) {
		$keys = explode('.', $path);
		$current = $attrs;
		foreach ($keys as $key) {
			if (!isset($current[$key])) {
				return null;
			}
			$current = $current[$key];
		}
		return $current;
	}

	/**
	 * Replace content in innerHTML and innerContent while preserving HTML structure (ported from GutenbergContentReplacer)
	 */
	protected function replaceInGutenbergHtmlContent(&$block, $blockData, $oldContentMap) {
		if (empty($blockData['contents']) || empty($oldContentMap)) return;
		$replacements = [];
		foreach ($blockData['contents'] as $content) {
			$attribute = $content['attribute'];
			$newContent = $content['content'];
			if (isset($oldContentMap[$attribute])) {
				$oldAttributeContent = $oldContentMap[$attribute];
				$decodedUnicodeContent = json_decode('"' . $oldAttributeContent . '"');
				$normalizedAttributeContent = $this->normalizeGutenbergUnicodeContent($oldAttributeContent);
				$normalizedNewContent = $this->normalizeGutenbergUnicodeContent($newContent);
				if ($normalizedAttributeContent !== $normalizedNewContent) {
					$replacements[] = [
						'originalFormat' => $oldAttributeContent,
						'decodedFormat' => $decodedUnicodeContent,
						'normalizedFormat' => $normalizedAttributeContent,
						'newContent' => $newContent,
						'attribute' => $attribute
					];
				}
			}
		}
		if (!empty($block['innerHTML']) && !empty($replacements)) {
			$block['innerHTML'] = $this->replaceGutenbergContentInHtml($block['innerHTML'], $replacements);
		}
		if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
			foreach ($block['innerContent'] as &$content) {
				if (is_string($content)) {
					$content = $this->replaceGutenbergContentInHtml($content, $replacements);
				}
			}
		}
	}

	/**
	 * Replace content in HTML while preserving structure and handling Unicode (ported from GutenbergContentReplacer)
	 */
	protected function replaceGutenbergContentInHtml($html, $replacements) {
		foreach ($replacements as $replacement) {
			$originalFormat = $replacement['originalFormat'];
			$decodedFormat = $replacement['decodedFormat'];
			$normalizedFormat = $replacement['normalizedFormat'];
			$newContent = $replacement['newContent'];
			if (empty($originalFormat)) continue;
			$htmlNewContent = $newContent;
			$html = str_replace($originalFormat, $htmlNewContent, $html);
			if ($decodedFormat !== null && $decodedFormat !== $originalFormat) {
				$html = str_replace($decodedFormat, $htmlNewContent, $html);
			}
			if ($normalizedFormat !== $decodedFormat && $normalizedFormat !== $originalFormat) {
				$html = str_replace($normalizedFormat, $htmlNewContent, $html);
			}
		}
		return $html;
	}

	/**
	 * Normalize Unicode content to handle different apostrophe types and other Unicode variations (ported from GutenbergContentReplacer)
	 */
	protected function normalizeGutenbergUnicodeContent($content) {
		$decoded = json_decode('"' . $content . '"');
		if ($decoded !== null) {
			$content = $decoded;
		}
		$unicodeReplacements = [
			'\u2019' => "'",
			'\u2018' => "'",
			'\u201C' => '"',
			'\u201D' => '"',
			'\u2013' => '-',
			'\u2014' => '-',
			'\u2026' => '...',
			"\u{2019}" => "'",
			"\u{2018}" => "'",
			"\u{201C}" => '"',
			"\u{201D}" => '"',
			"\u{2013}" => '-',
			"\u{2014}" => '-',
			"\u{2026}" => '...'
		];
		return str_replace(array_keys($unicodeReplacements), array_values($unicodeReplacements), $content);
	}

	/**
	 * Convert content to HTML format (handle line breaks and inline tags) (ported from GutenbergContentReplacer)
	 */
	protected function convertGutenbergToHtmlFormat($content) {
		$content = str_replace("\n", '<br>', $content);
		$content = str_replace("\r\n", '<br>', $content);
		return $content;
	}

	/**
	 * Recursively flatten a nested Gutenberg AI array by extracting blocks with 'contents' and using their blockId as key
	 *
	 * @param array $array The array to flatten
	 * @param array $flat Reference to the flattened array
	 * @return array The flattened array
	 */
	protected function flattenGutenbergById($array, &$flat = []) {
		foreach ($array as $key => $value) {
			if (is_array($value)) {
				// If this is a block with 'blockName' and 'contents', use its parent key as ID
				if (isset($value['blockName']) && isset($value['contents'])) {
					$flat[$key] = $value;
				}
				// Recurse into children
				$this->flattenGutenbergById($value, $flat);
			}
		}
		return $flat;
	}

	/**
	 * Write debug file only if TEMPLATELY_DEV_VIEWS is defined and true
	 * Handles .ai.json to .ao.json or .og.json as appropriate
	 */
	protected function writeDebugFile($ai_file, $data, $type = 'ao') {
		if ((defined('TEMPLATELY_DEV') && TEMPLATELY_DEV) || (defined('IMPORT_DEBUG') && IMPORT_DEBUG)) {
			$replace = ".{$type}.json";
			$debug_file = str_replace('.json', $replace, $ai_file);
			file_put_contents($debug_file, json_encode($data));
		}
	}

	/**
	 * Replace the inner content of tags with given class names in the HTML.
	 * Supports indexed class names (e.g., "eb-feature-list-title.0", "eb-feature-list-title.1").
	 * Falls back to regex if DOMDocument does not find the class.
	 *
	 * @param string $html The HTML string.
	 * @param array $contents Array of ['attribute' => className, 'content' => newContent]
	 * @return string The updated HTML.
	 */
	protected function replaceContentByClassName($html, $contents) {
		$classExists = false;
		foreach ($contents as $item) {
			$className = $item['attribute'];
			// Extract base class name (remove index if present)
			$baseClassName = $this->extractBaseClassName($className);
			if (preg_match('/class=["\'][^"\']*\b' . preg_quote($baseClassName, '/') . '\b[^"\']*["\']/', $html)) {
				$classExists = true;
				break;
			}
		}
		if (!$classExists) {
			return $html; // No relevant class found, skip both methods
		}

		if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
			return $this->replaceContentByClassNameDom($html, $contents);
		} else {
			return $this->replaceContentByClassNameRegex($html, $contents);
		}
	}

	/**
	 * Extract base class name from indexed class name.
	 *
	 * @param string $className The class name (e.g., "eb-feature-list-title.0")
	 * @return string The base class name (e.g., "eb-feature-list-title")
	 */
	protected function extractBaseClassName($className) {
		// Check if class name has numeric index at the end
		if (preg_match('/^(.+)\.(\d+)$/', $className, $matches)) {
			return $matches[1]; // Return base class name
		}
		return $className; // Return original if no index found
	}

	/**
	 * Extract index from indexed class name.
	 *
	 * @param string $className The class name (e.g., "eb-feature-list-title.0")
	 * @return int|null The index (e.g., 0) or null if no index found
	 */
	protected function extractClassIndex($className) {
		// Check if class name has numeric index at the end
		if (preg_match('/^(.+)\.(\d+)$/', $className, $matches)) {
			return (int)$matches[2]; // Return index as integer
		}
		return null; // Return null if no index found
	}

	/**
	 * Replace the inner content of tags with given class names in the HTML using DOMDocument.
	 * Supports indexed class names (e.g., "eb-feature-list-title.0", "eb-feature-list-title.1").
	 *
	 * Note: While CSS selectors would be more readable, PHP's DOMDocument doesn't natively support
	 * CSS selectors. We use XPath which is the standard way to query DOM elements in PHP.
	 * For CSS selector support, you would need a third-party library like symfony/css-selector
	 * or QueryPath, but we keep this implementation dependency-free.
	 *
	 * @param string $html The HTML string.
	 * @param array $contents Array of ['attribute' => className, 'content' => newContent]
	 * @return string The updated HTML.
	 */
	protected function replaceContentByClassNameDom($html, $contents) {
		$dom = new \DOMDocument();
		// Suppress errors due to HTML5 tags or fragments
		$html = $this->escapeInvalidEntities($html);
		@$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

		$xpath = new \DOMXPath($dom);
		foreach ($contents as $item) {
			$className = $item['attribute'];
			$newContent = $this->escapeInvalidEntities($item['content']);
			// $newContent = $item['content'];

			// Extract base class name and index
			$baseClassName = $this->extractBaseClassName($className);
			$targetIndex = $this->extractClassIndex($className);

			// Find elements by base class name using XPath
			// XPath equivalent to CSS selector: .baseClassName
			$nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' $baseClassName ')]");

			if ($targetIndex !== null) {
				// If indexed, only replace the element at the specific index
				if (isset($nodes[$targetIndex])) {
					$nodes[$targetIndex]->nodeValue = $newContent;
				}
			} else {
				// If not indexed, replace all elements with the class
				foreach ($nodes as $node) {
					$node->nodeValue = $newContent;
				}
			}
		}
		// Remove the XML encoding declaration
		$result = $dom->saveHTML();
		$result = preg_replace('/^<\?xml.*?\?>/', '', $result);
		return $result;
	}

	/**
	 * Replace the inner content of tags with given class names in the HTML using regex.
	 * Supports indexed class names (e.g., "eb-feature-list-title.0", "eb-feature-list-title.1").
	 *
	 * @param string $html The HTML string.
	 * @param array $contents Array of ['attribute' => className, 'content' => newContent]
	 * @return string The updated HTML.
	 */
	protected function replaceContentByClassNameRegex($html, $contents) {
		foreach ($contents as $item) {
			$className = $item['attribute'];
			$newContent = $item['content'];

			// Extract base class name and index
			$baseClassName = $this->extractBaseClassName($className);
			$targetIndex = $this->extractClassIndex($className);

			if ($targetIndex !== null) {
				// Handle indexed replacement
				$html = $this->replaceContentByClassNameRegexIndexed($html, $baseClassName, $newContent, $targetIndex);
			} else {
				// Handle non-indexed replacement (original behavior)
				$quotedClassName = preg_quote($className, '/');
				$pattern = '/(<([a-z0-9]+)[^>]*class="[^"]*\b' . $quotedClassName . '\b[^"]*"[^>]*>)(.*?)(<\/\2>)/is';
				$replacement = '$1' . $newContent . '$4';
				$html = preg_replace($pattern, $replacement, $html);
			}
		}
		return $html;
	}

	/**
	 * Replace content for a specific indexed occurrence of a class name using regex.
	 *
	 * @param string $html The HTML string.
	 * @param string $baseClassName The base class name (without index).
	 * @param string $newContent The new content to replace.
	 * @param int $targetIndex The zero-based index of the element to replace.
	 * @return string The updated HTML.
	 */
	protected function replaceContentByClassNameRegexIndexed($html, $baseClassName, $newContent, $targetIndex) {
		$quotedClassName = preg_quote($baseClassName, '/');
		/*
		Regex explanation:
		- (<([a-z0-9]+)[^>]*class="[^"]*\b$baseClassName\b[^"]*"[^>]*>)
			- (<([a-z0-9]+)[^>]* ... >) : Captures the opening tag with any attributes
			- ([a-z0-9]+) : Captures the tag name (e.g., p, div, span)
			- class="[^"]*\b$baseClassName\b[^"]*" : Ensures the class attribute contains the exact base class name (word boundary)
		- (.*?) : Captures everything inside the tag (non-greedy)
		- (<\/\2>) : Matches the corresponding closing tag (\2 is the tag name from earlier)
		Flags:
		- i : case-insensitive (for tag names)
		- s : dot matches newlines
		*/
		$pattern = '/(<([a-z0-9]+)[^>]*class="[^"]*\b' . $quotedClassName . '\b[^"]*"[^>]*>)(.*?)(<\/\2>)/is';

		$currentIndex = 0;
		$result = preg_replace_callback($pattern, function($matches) use ($newContent, $targetIndex, &$currentIndex) {
			if ($currentIndex == $targetIndex) {
				$currentIndex++;
				return $matches[1] . $newContent . $matches[4];
			}
			$currentIndex++;
			return $matches[0]; // Return original match unchanged
		}, $html);

		return $result;
	}

	/**
	 * Clean block name by removing namespace/plugin prefix
	 *
	 * @param string $block_name The full block name
	 *
	 * @return string Cleaned block name without prefix
	 */
	private function cleanBlockName( $block_name ) {
		// Remove namespace/plugin prefix (everything before the last slash)
		$parts = explode( '/', $block_name );

		return end( $parts );
	}

	/**
	 * Escape invalid entities in HTML to prevent DOMDocument warnings.
	 *
	 * @param string $html The HTML string to escape.
	 * @return string The escaped HTML string.
	 */
	protected function escapeInvalidEntities($html) {
		// Replace & not followed by one of: #, a-z, A-Z, or 0-9, and then a semicolon
		return preg_replace('/&(?!(#[0-9]+|[a-zA-Z0-9]+);)/', '&amp;', $html);
	}
}
