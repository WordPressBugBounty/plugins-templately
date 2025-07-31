# Indexed Class Names Enhancement

## Overview

The `AIContentHelper` trait has been enhanced to support indexed class names for precise element targeting. This allows you to replace content in specific occurrences of elements with the same class name.

## Features

### Indexed Class Name Format
- **Format**: `{base-class-name}.{index}`
- **Example**: `eb-feature-list-title.0`, `eb-feature-list-title.1`, etc.
- **Index**: Zero-based (0 = first occurrence, 1 = second occurrence, etc.)

### Supported Methods
1. **`replaceContentByClassNameDom()`** - Uses DOMDocument and XPath
2. **`replaceContentByClassNameRegex()`** - Uses regular expressions
3. **`replaceContentByClassNameDomWithCssSelectors()`** - Optional CSS selector implementation

## Usage Examples

### Basic Indexed Replacement
```php
$contents = [
    ["attribute" => "eb-feature-list-title.0", "content" => "facebook.com/OurDentalClinic"],
    ["attribute" => "eb-feature-list-title.1", "content" => "twitter.com/OurDentalClinic"],
    ["attribute" => "eb-feature-list-title.2", "content" => "instagram.com/OurDentalClinic"],
    ["attribute" => "eb-feature-list-title.3", "content" => "linkedin.com/company/OurDentalClinic"]
];

$html = '
<div class="eb-feature-list-title">First Title</div>
<div class="eb-feature-list-title">Second Title</div>
<div class="eb-feature-list-title">Third Title</div>
<div class="eb-feature-list-title">Fourth Title</div>
';

$result = $this->replaceContentByClassName($html, $contents);
```

### Mixed Indexed and Non-Indexed
```php
$contents = [
    ["attribute" => "eb-feature-list-title.1", "content" => "Only Second Changed"],
    ["attribute" => "eb-testimonial-name", "content" => "All Names Changed"]
];
```

## Implementation Details

### Helper Methods

#### `extractBaseClassName($className)`
- Extracts the base class name from an indexed class name
- Example: `"eb-feature-list-title.0"` → `"eb-feature-list-title"`

#### `extractClassIndex($className)`
- Extracts the numeric index from an indexed class name
- Example: `"eb-feature-list-title.0"` → `0`
- Returns `null` if no index is found

### DOM Method Enhancement
- Uses XPath to find elements by base class name
- Targets specific element by index using array notation
- Gracefully handles out-of-bounds indices

### Regex Method Enhancement
- Uses `preg_replace_callback()` for indexed replacements
- Maintains counter to track element occurrences
- Preserves original behavior for non-indexed class names

## CSS Selectors Alternative

For better readability, an optional CSS selector implementation is provided:

### Requirements
```bash
composer require symfony/css-selector
```

### Usage
Replace the call to `replaceContentByClassNameDom()` with `replaceContentByClassNameDomWithCssSelectors()`.

### Benefits
- More readable CSS selector syntax: `.eb-feature-list-title`
- Automatic fallback to XPath if library is not available
- Same functionality with cleaner code

## Backward Compatibility

- ✅ Fully backward compatible with existing non-indexed class names
- ✅ Existing code continues to work without changes
- ✅ New indexed functionality is opt-in

## Error Handling

- **Out-of-bounds indices**: Silently ignored (no replacement occurs)
- **Invalid format**: Treated as regular class name
- **Missing elements**: No error thrown, graceful degradation

## Performance Considerations

- **DOM Method**: Slightly slower due to DOM parsing, but more reliable
- **Regex Method**: Faster for simple HTML, but less robust for complex structures
- **CSS Selectors**: Similar performance to XPath, but requires additional dependency

## Testing

Run the comprehensive test suite:
```bash
php includes/Core/Importer/Utils/AIContentHelperIndexedTest.php
```

The test covers:
- Basic indexed replacement
- Mixed indexed and non-indexed
- Out-of-bounds indices
- Multiple classes per element
- Nested elements
- Helper method validation
