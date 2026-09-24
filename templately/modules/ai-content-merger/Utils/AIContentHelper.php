<?php

namespace Templately\Modules\AiContentMerger\Utils;

/**
 * AI Content Helper
 *
 * Standalone, self-contained AI content merger (spec 022 FR-011): platform-aware merging
 * of AI-generated content into the original template — flatten-by-id, unicode
 * normalization, Elementor/Gutenberg dot-notation merge, and HTML class-based replacement.
 *
 * Every method is a pure static with ZERO cross-module dependencies. Session/path
 * resolution and file IO (reading the .ai.json / original JSON, writing the .ao.json debug
 * file) are the CONSUMER's responsibility (FSI's Finalizer). The public entry point is the
 * static merge() dispatcher.
 */
class AIContentHelper {
	/**
	 * Parity warnings accumulated during the most recent Gutenberg merge.
	 *
	 * A Gutenberg static block is validated by re-running its JS `save(attributes)` and
	 * comparing the output against the markup stored in the post. So a merge that writes new
	 * text into ONE of {attributes, markup} without the other produces a block the editor
	 * reports as "Block contains unexpected or invalid content" — and, because the stored
	 * markup is what the front end serves, a page that never shows the AI copy at all.
	 *
	 * Every replacement path below therefore moves both sides together. When one side cannot
	 * be updated (the old string is not found where it was expected — entity/encoding drift,
	 * an unknown block shape), that is recorded here instead of failing silently, so the
	 * Finalizer can log it and the post-import rebuild pass knows which posts to visit.
	 *
	 * @var array<int,array{blockName:string,blockId:string,attribute:string,reason:string}>
	 */
	private static $parity_warnings = [];

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
	 * Merge AI-generated content into the original template for a given platform.
	 *
	 * Pure, self-contained dispatcher (spec 022 FR-011): the caller supplies the already-read
	 * AI payload and original template; this routes to the platform-specific merger. Session
	 * resolution and file IO are the consumer's responsibility, not this class's.
	 *
	 * @param array  $payload  The decoded AI-generated template content.
	 * @param mixed  $template The decoded original template to merge into.
	 * @param string $platform Either 'elementor' or 'gutenberg'.
	 * @return mixed The merged template, or the untouched $template for an unknown platform.
	 */
	public static function merge( array $payload, $template, string $platform ) {
		if ( 'elementor' === $platform ) {
			return self::mergeAiContentWithOriginal( $payload, $template );
		}
		if ( 'gutenberg' === $platform ) {
			return self::mergeAiContentWithOriginalGutenberg( $payload, $template );
		}
		return $template;
	}

	/**
	 * Normalize escape characters in AI content
	 *
	 * Removes backslashes before closing tags (converts `<\/` and `<\\/` to `</`)
	 * This normalization is applied to all content values in the AI template's contents arrays.
	 *
	 * @param array $flat Reference to the flattened AI content array
	 * @return void Modifies the array in place
	 */
	public static function normalizeAiContentEscapeCharacters(&$flat) {
		foreach ($flat as &$element) {
			if (isset($element['contents']) && is_array($element['contents'])) {
				foreach ($element['contents'] as $index => &$content_item) {
					if (isset($content_item['content']) && is_string($content_item['content'])) {
						// Normalize content: unescape closing tags (remove backslash before </)
						$content_item['content'] = str_replace(['<\/', '<\\/'], '</', $content_item['content']);
					}
					else {
						unset($element['contents'][$index]);
					}
				}
			}
		}
	}

	/**
	 * Recursively flatten a nested array by extracting elements with 'contents' and using their ID as key
	 *
	 * @param array $array The array to flatten
	 * @param array $flat Reference to the flattened array
	 * @return array The flattened array
	 */
	public static function flattenById($array, &$flat = []) {
		foreach ($array as $key => $value) {
			if (is_array($value)) {
				// If this is an element with 'widgetType' and 'contents', use its parent key as ID
				if (isset($value['widgetType']) && isset($value['contents'])) {
					$flat[$key] = $value;
				}
				// Recurse into children
				self::flattenById($value, $flat);
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
	public static function setNestedValue(&$array, $path, $value) {
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
			self::setNestedValue($array[$key], $path, $value);
		}
	}

	/**
	 * Merge AI content with the original template
	 *
	 * @param array $ai_template_json The AI template JSON
	 * @param array $original_template_json The original template JSON data
	 * @return array The merged template JSON
	 */
	public static function mergeAiContentWithOriginal($ai_template_json, $original_template_json) {
		// Some callers pass the AI template as a JSON-encoded string; decode it first.
		if (is_string($ai_template_json)) {
			$ai_template_json = json_decode($ai_template_json, true);
		}

		// 1. Flatten the AI template
		$flat = self::flattenById($ai_template_json);

		// 2. Normalize escape characters in AI content early in the pipeline
		self::normalizeAiContentEscapeCharacters($flat);

		$keys = array_keys($flat);

		// 3. Loop through original content only once and update elements directly
		self::updateElementorContentRecursively($flat, $keys, $original_template_json['content']);

		return $original_template_json;
	}

	/**
	 * Update Elementor content recursively by looping through original content only once
	 *
	 * @param array $flat The flattened AI content array
	 * @param array $keys Array of element IDs from the flat array
	 * @param array $content Reference to the original content to update
	 */
	public static function updateElementorContentRecursively($flat, array $keys, &$content) {
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
						$content_value = is_string($item['content'])
							? str_replace(['<\/', '<\\/'], '</', $item['content'])
							: $item['content'];

						// Support for dot notation in attribute paths
						if (strpos($item['attribute'], '.') !== false) {
							$path = explode('.', $item['attribute']);
							self::setNestedValue($content['settings'], $path, $content_value);
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
				self::updateElementorContentRecursively($flat, $keys, $element);
			}
		}

		// Recurse through all other array elements
		foreach ($content as &$value) {
			if (is_array($value)) {
				self::updateElementorContentRecursively($flat, $keys, $value);
			}
		}
	}

	/**
	 * Merge AI content with the original Gutenberg template using advanced content replacement
	 *
	 * @param array $ai_template_json The AI template JSON
	 * @param array $original_template_json The original template JSON data
	 * @return array The merged template JSON
	 */
	public static function mergeAiContentWithOriginalGutenberg($ai_template_json, $original_template_json) {
		if (empty($original_template_json['content'])) {
			return $original_template_json;
		}

		// Some callers pass the AI template as a JSON-encoded string; decode it first.
		if (is_string($ai_template_json)) {
			$ai_template_json = json_decode($ai_template_json, true);
		}

		self::$parity_warnings = [];

		// 1. Flatten the AI template by block ID (for blocks with 'contents')
		$flat = [];
		self::flattenGutenbergById($ai_template_json, $flat);

		// 2. Normalize escape characters in AI content early in the pipeline
		self::normalizeAiContentEscapeCharacters($flat);

		$generated = $flat;
		$keys = array_keys($generated);

		// 3. Parse the original Gutenberg content
		$blocks = parse_blocks($original_template_json['content']);

		// 4. Clean invalid blocks from parsed content
		$blocks = self::cleanInvalidBlocks($blocks);

		// 5. Replace content recursively using advanced replacer logic
		$blocks = self::replaceGutenbergContentRecursively($generated, $keys, $blocks);

		// 6. Clean invalid blocks before serialization
		$blocks = self::cleanInvalidBlocks($blocks);

		// 7. Serialize the updated blocks back to content
		$original_template_json['content'] = serialize_blocks($blocks);

		return $original_template_json;
	}

	/**
	 * Replace content recursively in Gutenberg blocks (ported from GutenbergContentReplacer)
	 */
	public static function replaceGutenbergContentRecursively($generated, array $keys, &$blocks) {
		$htmlSources = [
			'testimonial',
			'feature-list',
			'notice',
			'pricing-table',
			'typing-text',
			'interactive-promo',
			'call-to-action'
		];

		foreach ($blocks as &$block) {
			if (!empty($block['attrs']['blockId'])) {
				$blockId = $block['attrs']['blockId'];
				if (in_array($blockId, $keys)) {
					$blockData = $generated[$blockId];
					$block_name = self::cleanBlockName( $block['blockName'] );

					// Store old content BEFORE updating attributes
					$oldContentMap = [];
					if (!empty($blockData['contents']) && !in_array($block_name, $htmlSources)) {
						foreach ($blockData['contents'] as $content) {
							$attribute = $content['attribute'];
							$oldContent = self::getNestedGutenbergAttribute($block['attrs'], $attribute);
							if ($oldContent !== null) {
								$oldContentMap[$attribute] = $oldContent;
							}
						}
					}

					// Replace content in attributes
					if (!empty($blockData['contents']) && !in_array($block_name, $htmlSources)) {
						foreach ($blockData['contents'] as $content) {
							$attribute = $content['attribute'];
							$newContent = $content['content'];
							self::setNestedGutenbergAttribute($block['attrs'], $attribute, $newContent);
						}
					}

					// Replace content in innerHTML and innerContent using old content
					if (!empty($block['innerHTML']) || !empty($block['innerContent'])) {
						self::replaceInGutenbergHtmlContent($block, $blockData, $oldContentMap);
					}

					if(in_array($block_name, $htmlSources)){
						if (!empty($blockData['contents'])) {
							// These blocks are addressed by CLASS NAME because that is where their copy
							// actually lives: every block in $htmlSources declares its text attributes
							// with a `source` + `selector` (EB's notice -> source:"text" on
							// `.eb-notice-title`; feature-list -> source:"query" over the `li`s), so the
							// values are PARSED BACK OUT OF THE MARKUP and never stored in the block
							// comment. Rewriting the markup is therefore the complete fix for them.
							//
							// The attribute pass below is a safety net for the mixed case — a block that
							// ALSO keeps a comment-stored copy of the same string, which would then go
							// stale. Read the old text out of the markup FIRST: after the replacement it
							// is gone, and it is the only key that can identify such an attribute (there
							// is no class-name -> attribute map, and inventing one would rot on every EB
							// markup change).
							$oldByClass = self::extractContentByClassName(
								is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '',
								$blockData['contents']
							);

							if (!empty($block['innerHTML'])) {
								$block['innerHTML'] = self::replaceContentByClassName($block['innerHTML'], $blockData['contents']);
							}
							if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
								foreach ($block['innerContent'] as &$content) {
									if (is_string($content)) {
										$content = self::replaceContentByClassName($content, $blockData['contents']);
									}
								}
								unset($content);
							}

							self::syncAttrsFromClassReplacements($block, $blockData['contents'], $oldByClass);
						}
					}
					if($block["blockName"] === 'essential-blocks/accordion'){
						$block_inner_block_ids = array_map(function($innerBlock) {
							return $innerBlock["attrs"]["blockId"] ?? null;
						}, $block["innerBlocks"]);
						$_generated = array_fill_keys($block_inner_block_ids, ['contents' => $blockData['contents']]);

						if(isset($block["innerBlocks"][0]["attrs"]["accordionLists"]) && count($block["innerBlocks"][0]["attrs"]["accordionLists"]) > 1){
							$block["innerBlocks"] = self::replaceGutenbergContentRecursively($_generated, $block_inner_block_ids, $block['innerBlocks']);
						}
						else {
							// The live path: EB syncs each accordion-item's `accordionLists` down from
							// the parent as a SINGLE-entry array (accordion-item/src/edit.js), so the
							// count > 1 branch above never fires for current EB markup.
							//
							// `accordion-item/src/save.js` renders `foundItem?.title` straight out of
							// this attribute, so writing the updated entry here WITHOUT rewriting the
							// item's own markup guarantees a save()/markup mismatch: the editor shows
							// "Attempt recovery" on every FAQ row and the front end keeps serving the
							// pre-AI titles. Move both sides together.
							$attrAccordionLists = $block["attrs"]["accordionLists"];
							$ids                = array_column($attrAccordionLists, 'id');

							foreach ($block["innerBlocks"] as $key => $accordion) {
								$itemLists = $accordion["attrs"]["accordionLists"] ?? [];
								if (!is_array($itemLists)) {
									continue;
								}

								foreach($itemLists as $accordionKey => $accordionList){
									if (!is_array($accordionList) || !isset($accordionList['id'])) {
										continue;
									}

									// search $block["attrs"]["accordionLists"] by $accordionList["id"] and replace $accordionList with searched one
									$foundIndex = array_search($accordionList["id"], $ids);
									if ($foundIndex === false) {
										continue;
									}

									$updatedEntry = $attrAccordionLists[$foundIndex];
									$pairs        = self::diffStringFields($accordionList, $updatedEntry);

									$block["innerBlocks"][$key]["attrs"]["accordionLists"][$accordionKey] = $updatedEntry;

									if (!empty($pairs)) {
										self::applyReplacementsToBlockMarkup(
											$block["innerBlocks"][$key],
											self::buildReplacementRecords($pairs)
										);
									}
								}
							}
						}
					}
				}

				// Process nested blocks recursively
				if (!empty($block['innerBlocks'])) {
					$block['innerBlocks'] = self::replaceGutenbergContentRecursively($generated, $keys, $block['innerBlocks']);
				}
			}
		}
		return $blocks;
	}

	/**
	 * Parity warnings from the most recent Gutenberg merge.
	 *
	 * Empty means every replacement landed on both the attributes and the markup. A non-empty
	 * list names the blocks where one side could not be updated — those are exactly the blocks
	 * that will open with a recovery banner, so consumers (the FSI Finalizer log, the
	 * post-import rebuild queue) use it to decide what to report and what to revisit.
	 *
	 * @return array<int,array{blockName:string,blockId:string,attribute:string,reason:string}>
	 */
	public static function collect_parity_warnings(): array {
		return self::$parity_warnings;
	}

	/**
	 * Record one attribute/markup parity failure. Never throws — a merge that cannot keep the
	 * two sides in step must still produce content.
	 */
	private static function addParityWarning($blockName, $blockId, $attribute, $reason) {
		self::$parity_warnings[] = [
			'blockName' => (string) $blockName,
			'blockId'   => (string) $blockId,
			'attribute' => (string) $attribute,
			'reason'    => (string) $reason,
		];
	}

	/**
	 * String fields that differ between two versions of the same structured entry.
	 *
	 * Deliberately NOT limited to `title`: an accordion entry also carries
	 * `titlePrefixText`/`titleSuffixText`/`imageAlt`, and EB adds fields over time. Comparing
	 * every string field keeps this correct as the shape grows instead of silently covering
	 * one key.
	 *
	 * @param array $old Entry before the update.
	 * @param array $new Entry after the update.
	 * @return array<int,array{attribute:string,old:string,new:string}>
	 */
	private static function diffStringFields($old, $new) {
		$pairs = [];

		if (!is_array($old) || !is_array($new)) {
			return $pairs;
		}

		foreach ($new as $field => $value) {
			if (!is_string($value) || !isset($old[$field]) || !is_string($old[$field])) {
				continue;
			}
			if ($old[$field] === '' || $old[$field] === $value) {
				continue;
			}
			$pairs[] = [
				'attribute' => (string) $field,
				'old'       => $old[$field],
				'new'       => $value,
			];
		}

		return $pairs;
	}

	/**
	 * Shape old/new pairs into the replacement records `replaceGutenbergContentInHtml()` expects.
	 *
	 * Longest-first, for the same reason `replaceInGutenbergHtmlContent()` sorts: a short string
	 * that is a prefix of a longer one would otherwise consume it.
	 *
	 * @param array<int,array{attribute:string,old:string,new:string}> $pairs
	 * @return array
	 */
	private static function buildReplacementRecords($pairs) {
		$replacements = [];

		foreach ($pairs as $pair) {
			$replacements[] = [
				'originalFormat'   => $pair['old'],
				'decodedFormat'    => json_decode('"' . $pair['old'] . '"'),
				'normalizedFormat' => self::normalizeGutenbergUnicodeContent($pair['old']),
				'newContent'       => $pair['new'],
				'attribute'        => $pair['attribute'],
			];
		}

		usort($replacements, function ($a, $b) {
			return strlen($b['originalFormat']) - strlen($a['originalFormat']);
		});

		return $replacements;
	}

	/**
	 * Apply replacement records to a block's own markup (`innerHTML` + `innerContent`).
	 *
	 * Records a parity warning for any replacement whose old text is still present afterwards —
	 * that block's save() output will not match what is stored.
	 *
	 * @param array $block        Block, by reference.
	 * @param array $replacements Records from {@see self::buildReplacementRecords()}.
	 */
	private static function applyReplacementsToBlockMarkup(&$block, $replacements) {
		if (empty($replacements)) {
			return;
		}

		$before = self::ownMarkup($block);

		if (!empty($block['innerHTML']) && is_string($block['innerHTML'])) {
			$block['innerHTML'] = self::replaceGutenbergContentInHtml($block['innerHTML'], $replacements);
		}

		if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
			foreach ($block['innerContent'] as $index => $chunk) {
				if (is_string($chunk)) {
					$block['innerContent'][$index] = self::replaceGutenbergContentInHtml($chunk, $replacements);
				}
			}
		}

		$after       = self::ownMarkup($block);
		$hasChildren = !empty($block['innerBlocks']);

		foreach ($replacements as $replacement) {
			$old = $replacement['originalFormat'];
			$new = $replacement['newContent'];

			if ($old === '' || $new === '') {
				continue;
			}

			$wasHere = strpos($before, $old) !== false;

			if ($wasHere) {
				// The copy IS in this block's markup. If it survived the replacement the block is
				// now desynced from its own attributes — this is the "Attempt recovery" case.
				if (strpos($after, $old) !== false) {
					self::addParityWarning($block['blockName'] ?? '', $block['attrs']['blockId'] ?? '', $replacement['attribute'], 'markup_not_updated');
				}
				continue;
			}

			// The copy was not in this block's own markup. `parse_blocks` gives each block only
			// its OWN chunks, so for a container (an accordion parent, a wrapper) the text
			// legitimately lives in a child block that is walked separately — silence there.
			// With no children there is nowhere else for it to be, so the replacement had no
			// target and the two sides cannot agree.
			if (!$hasChildren && strpos($after, $new) === false && trim($after) !== '') {
				self::addParityWarning($block['blockName'] ?? '', $block['attrs']['blockId'] ?? '', $replacement['attribute'], 'markup_not_updated');
			}
		}
	}

	/**
	 * A block's OWN markup — `innerHTML` plus its string `innerContent` chunks, never its
	 * children's (`parse_blocks` keeps those in `innerBlocks`, walked separately).
	 *
	 * @param array $block
	 * @return string
	 */
	private static function ownMarkup($block) {
		$markup = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';

		if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
			foreach ($block['innerContent'] as $chunk) {
				if (is_string($chunk)) {
					$markup .= $chunk;
				}
			}
		}

		return $markup;
	}

	/**
	 * Current text of each class-addressed target, keyed by the class name used to address it.
	 *
	 * Must be called BEFORE the class-based replacement runs — afterwards the old text is gone,
	 * and it is the only thing that can identify the attribute holding the same copy.
	 *
	 * @param string $html     Block markup.
	 * @param array  $contents `[['attribute' => className, 'content' => newContent], …]`
	 * @return array<string,string> className => old text
	 */
	public static function extractContentByClassName($html, $contents) {
		$found = [];

		if (!is_string($html) || $html === '' || empty($contents)) {
			return $found;
		}
		if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
			return $found;
		}

		$dom = new \DOMDocument();
		@$dom->loadHTML('<?xml encoding="utf-8" ?>' . self::escapeInvalidEntities($html), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		$xpath = new \DOMXPath($dom);

		foreach ($contents as $item) {
			if (!isset($item['attribute'])) {
				continue;
			}
			$className     = $item['attribute'];
			$baseClassName = self::extractBaseClassName($className);
			$targetIndex   = self::extractClassIndex($className);

			$nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' $baseClassName ')]");
			if (!$nodes || $nodes->length === 0) {
				continue;
			}

			$node = $targetIndex !== null ? ($nodes[$targetIndex] ?? null) : $nodes[0];
			if ($node !== null) {
				$found[$className] = $node->nodeValue;
			}
		}

		return $found;
	}

	/**
	 * Write class-replaced copy back into the block's attributes, matched BY VALUE.
	 *
	 * For the `$htmlSources` blocks the copy normally lives ONLY in the markup (their text
	 * attributes are `source`d from selectors), so this usually finds nothing — that is the
	 * expected, healthy case and is deliberately NOT warned about. It exists for the mixed
	 * block that also keeps a comment-stored copy of the same string, which would otherwise go
	 * stale and make save() disagree with the markup.
	 *
	 * Matching on the exact old string needs no class-name -> attribute table and cannot rot
	 * when EB renames a class; a table would have to be maintained per block per release, and a
	 * stale entry fails silently.
	 *
	 * Exact FULL-string equality only (never substring), and only on string scalars, so an
	 * unrelated attribute that merely contains the text is left alone.
	 *
	 * @param array $block      Block, by reference.
	 * @param array $contents   `[['attribute' => className, 'content' => newContent], …]`
	 * @param array $oldByClass className => old text, from {@see self::extractContentByClassName()}
	 */
	private static function syncAttrsFromClassReplacements(&$block, $contents, $oldByClass) {
		if (empty($contents) || empty($oldByClass) || empty($block['attrs']) || !is_array($block['attrs'])) {
			return;
		}

		$map = [];
		foreach ($contents as $item) {
			if (!isset($item['attribute'], $item['content']) || !is_string($item['content'])) {
				continue;
			}
			$className = $item['attribute'];
			if (!isset($oldByClass[$className])) {
				continue;
			}

			$old = $oldByClass[$className];
			if (!is_string($old)) {
				continue;
			}

			$old = trim($old);
			if ($old === '' || $old === $item['content']) {
				continue;
			}

			$map[$old] = ['new' => $item['content'], 'attribute' => $className];
		}

		if (empty($map)) {
			return;
		}

		$written = [];
		self::replaceStringsInAttrs($block['attrs'], $map, $written);
	}

	/**
	 * Recursively replace exact string values inside an attribute tree.
	 *
	 * @param mixed $attrs   Attribute value/tree, by reference.
	 * @param array $map     oldString => ['new' => newString, 'attribute' => label]
	 * @param array $written oldString => hit count, by reference.
	 */
	private static function replaceStringsInAttrs(&$attrs, $map, &$written) {
		if (is_string($attrs)) {
			$key = trim($attrs);
			if (isset($map[$key])) {
				$attrs           = $map[$key]['new'];
				$written[$key]   = ($written[$key] ?? 0) + 1;
			}
			return;
		}

		if (is_array($attrs)) {
			foreach ($attrs as $key => $value) {
				self::replaceStringsInAttrs($attrs[$key], $map, $written);
			}
		}
	}

	/**
	 * Set nested attribute value using dot notation (ported from GutenbergContentReplacer)
	 */
	public static function setNestedGutenbergAttribute(&$attrs, $path, $value) {
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
	public static function getNestedGutenbergAttribute($attrs, $path) {
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
	public static function replaceInGutenbergHtmlContent(&$block, $blockData, $oldContentMap) {
		if (empty($blockData['contents']) || empty($oldContentMap)) return;
		$replacements = [];
		foreach ($blockData['contents'] as $content) {
			$attribute = $content['attribute'];
			$newContent = $content['content'];
			if (isset($oldContentMap[$attribute])) {
				$oldAttributeContent = $oldContentMap[$attribute];
				$decodedUnicodeContent = json_decode('"' . $oldAttributeContent . '"');
				$normalizedAttributeContent = self::normalizeGutenbergUnicodeContent($oldAttributeContent);
				$normalizedNewContent = self::normalizeGutenbergUnicodeContent($newContent);
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
		// sort $replacements by length of 'originalFormat' in descending order
		usort($replacements, function($a, $b) {
			return strlen($b['originalFormat']) - strlen($a['originalFormat']);
		});
		if (empty($replacements)) {
			return;
		}

		// One call, so the leftover check below sees the SAME markup the block ends up with —
		// the attributes have already been rewritten by the caller, so anything this fails to
		// replace is a desync that will surface as "Attempt recovery" in the editor.
		self::applyReplacementsToBlockMarkup($block, $replacements);
	}

	/**
	 * Replace content in HTML while preserving structure and handling Unicode (ported from GutenbergContentReplacer)
	 *
	 * Uses targeted replacement that avoids replacing text inside HTML attributes (href, src, data-*, etc.)
	 * to prevent breaking URLs and other attribute values.
	 */
	public static function replaceGutenbergContentInHtml($html, $replacements) {
		foreach ($replacements as $replacement) {
			$originalFormat = $replacement['originalFormat'];
			$decodedFormat = $replacement['decodedFormat'];
			$normalizedFormat = $replacement['normalizedFormat'];
			$newContent = $replacement['newContent'];
			if (empty($originalFormat)) continue;
			$htmlNewContent = $newContent;

			// Use targeted replacement that avoids HTML attributes
			$html = self::replaceTextOutsideAttributes($html, $originalFormat, $htmlNewContent);

			if ($decodedFormat !== null && $decodedFormat !== $originalFormat) {
				$html = self::replaceTextOutsideAttributes($html, $decodedFormat, $htmlNewContent);
			}
			if ($normalizedFormat !== $decodedFormat && $normalizedFormat !== $originalFormat) {
				$html = self::replaceTextOutsideAttributes($html, $normalizedFormat, $htmlNewContent);
			}
		}
		return $html;
	}

	/**
	 * Replace text in HTML only outside of HTML tags and attributes
	 *
	 * This function replaces occurrences of $oldText with $newText, but only when the text
	 * appears outside of HTML tags and attributes. This prevents unintended replacements
	 * inside URLs, src attributes, href attributes, and other HTML attributes.
	 *
	 * @param string $html The HTML content to process
	 * @param string $oldText The text to find and replace
	 * @param string $newText The replacement text
	 * @return string The HTML with replacements applied only outside of tags/attributes
	 */
	private static function replaceTextOutsideAttributes($html, $oldText, $newText) {
		if (empty($oldText) || $oldText === $newText) {
			return $html;
		}

		$result = '';
		$lastPos = 0;

		// Find all occurrences of the text
		while (($pos = strpos($html, $oldText, $lastPos)) !== false) {
			// Check if this occurrence is inside an HTML tag or attribute
			if (!self::isPositionInsideTag($html, $pos)) {
				// Not inside a tag, safe to replace
				$result .= substr($html, $lastPos, $pos - $lastPos) . $newText;
				$lastPos = $pos + strlen($oldText);
			} else {
				// Inside a tag, skip this occurrence
				$result .= substr($html, $lastPos, $pos - $lastPos + strlen($oldText));
				$lastPos = $pos + strlen($oldText);
			}
		}

		// Append remaining HTML
		$result .= substr($html, $lastPos);
		return $result;
	}

	/**
	 * Check if a position in HTML is inside an HTML attribute value
	 *
	 * This checks if the position is between quotes within an HTML tag.
	 * Returns true only if the position is inside an attribute value (between quotes),
	 * not just anywhere inside a tag.
	 *
	 * @param string $html The HTML content
	 * @param int $position The position to check
	 * @return bool True if the position is inside an attribute value, false otherwise
	 */
	private static function isPositionInsideTag($html, $position) {
		// Get the text before the position
		$beforeText = substr($html, 0, $position);

		// Find the last < and > before the position
		$lastOpenTag = strrpos($beforeText, '<');
		$lastCloseTag = strrpos($beforeText, '>');

		// If there's no unclosed tag, we're not inside a tag
		if ($lastOpenTag === false || ($lastCloseTag !== false && $lastOpenTag < $lastCloseTag)) {
			return false;
		}

		// We're inside a tag. Now check if we're inside an attribute value (between quotes)
		// Get the tag content from the last < to the position
		$tagContent = substr($html, $lastOpenTag, $position - $lastOpenTag);

		// Track whether we're inside double or single quotes by iterating through the tag content
		$inDoubleQuotes = false;
		$inSingleQuotes = false;

		for ($i = 0; $i < strlen($tagContent); $i++) {
			$char = $tagContent[$i];

			// Toggle quote state when we encounter a quote
			if ($char === '"' && !$inSingleQuotes) {
				$inDoubleQuotes = !$inDoubleQuotes;
			} elseif ($char === "'" && !$inDoubleQuotes) {
				$inSingleQuotes = !$inSingleQuotes;
			}
		}

		// We're inside an attribute value if we're inside either type of quotes
		return $inDoubleQuotes || $inSingleQuotes;
	}

	/**
	 * Normalize Unicode content to handle different apostrophe types and other Unicode variations (ported from GutenbergContentReplacer)
	 */
	public static function normalizeGutenbergUnicodeContent($content) {
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
	public static function convertGutenbergToHtmlFormat($content) {
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
	public static function flattenGutenbergById($array, &$flat = []) {
		foreach ($array as $key => $value) {
			if (is_array($value)) {
				// If this is a block with 'blockName' and 'contents', use its parent key as ID
				if (isset($value['blockName']) && isset($value['contents'])) {
					$flat[$key] = $value;
				}
				// Recurse into children
				self::flattenGutenbergById($value, $flat);
			}
		}
		return $flat;
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
	public static function replaceContentByClassName($html, $contents) {
		$classExists = false;
		foreach ($contents as $item) {
			$className = $item['attribute'];
			// Extract base class name (remove index if present)
			$baseClassName = self::extractBaseClassName($className);
			if (preg_match('/class=["\'][^"\']*\b' . preg_quote($baseClassName, '/') . '\b[^"\']*["\']/', $html)) {
				$classExists = true;
				break;
			}
		}
		if (!$classExists) {
			return $html; // No relevant class found, skip both methods
		}

		if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
			return self::replaceContentByClassNameDom($html, $contents);
		} else {
			return self::replaceContentByClassNameRegex($html, $contents);
		}
	}

	/**
	 * Extract base class name from indexed class name.
	 *
	 * @param string $className The class name (e.g., "eb-feature-list-title.0")
	 * @return string The base class name (e.g., "eb-feature-list-title")
	 */
	public static function extractBaseClassName($className) {
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
	public static function extractClassIndex($className) {
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
	public static function replaceContentByClassNameDom($html, $contents) {
		$dom = new \DOMDocument();
		// Suppress errors due to HTML5 tags or fragments
		$html = self::escapeInvalidEntities($html);
		@$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

		$xpath = new \DOMXPath($dom);
		foreach ($contents as $item) {
			$className = $item['attribute'];
			$newContent = self::escapeInvalidEntities($item['content']);
			// $newContent = $item['content'];

			// Extract base class name and index
			$baseClassName = self::extractBaseClassName($className);
			$targetIndex = self::extractClassIndex($className);

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
	public static function replaceContentByClassNameRegex($html, $contents) {
		foreach ($contents as $item) {
			$className = $item['attribute'];
			$newContent = $item['content'];

			// Extract base class name and index
			$baseClassName = self::extractBaseClassName($className);
			$targetIndex = self::extractClassIndex($className);

			if ($targetIndex !== null) {
				// Handle indexed replacement
				$html = self::replaceContentByClassNameRegexIndexed($html, $baseClassName, $newContent, $targetIndex);
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
	public static function replaceContentByClassNameRegexIndexed($html, $baseClassName, $newContent, $targetIndex) {
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
	public static function cleanBlockName( $block_name ) {
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
	public static function escapeInvalidEntities($html) {
		// Replace & not followed by one of: #, a-z, A-Z, or 0-9, and then a semicolon
		return preg_replace('/&(?!(#[0-9]+|[a-zA-Z0-9]+);)/', '&amp;', $html);
	}

	/**
	 * Remove invalid blocks from array
	 *
	 * @param array $blocks Array of blocks to clean
	 * @return array Cleaned array with only valid blocks
	 */
	public static function cleanInvalidBlocks(array $blocks) {
		$cleanedBlocks = [];

		foreach ($blocks as $block) {
			// Skip if not array
			if (!is_array($block)) {
				continue;
			}

			// Skip if blockName is null or empty
			if (empty($block['blockName'])) {
				continue;
			}

			// Skip if missing required properties
			if (!isset($block['attrs']) ||
				!isset($block['innerBlocks']) ||
				!isset($block['innerHTML']) ||
				!isset($block['innerContent'])) {
				continue;
			}

			// Clean nested blocks recursively
			if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
				$block['innerBlocks'] = self::cleanInvalidBlocks($block['innerBlocks']);
			}

			$cleanedBlocks[] = $block;
		}

		return $cleanedBlocks;
	}
}
