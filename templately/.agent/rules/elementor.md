# Elementor Integration

## Module Architecture
- Extend `elementorModules.editor.utils.Module` class
- Follow Elementor's organized module component architecture patterns
- Maintain consistency with Elementor's development standards

## Module Refactoring
- Replace jQuery event listeners with proper Module lifecycle methods
- Move hook registrations out of jQuery document ready
- Use Module's built-in event system instead of direct jQuery bindings

## Document Options Menu
- Use `registerAction` with `overwrite: true` and `visible: false` props
- Avoid complex removal methods for menu items
- Leverage Elementor's action system for clean integration

---

# Gutenberg Blocks Processing

## Block Parsing
- Use `parse_blocks()` function with recursive processing for nested blocks
- Extract text content from block attributes
- Replace `innerContent`/`innerHTML` with placeholders while maintaining block validation

## Placeholder Management
- Replace text content found in attributes with placeholders in `innerHTML`/`innerContent`
- Create placeholder IDs using `block_id` and attribute name
- Maintain block structure integrity during placeholder replacement

## Block Attributes
- `tagName` should not be in `text_field_keys` as it represents a tag name
- Focus on actual text content that needs translation, not structural elements
