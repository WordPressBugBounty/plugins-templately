# Local Site Polling Implementation (Refactored)

## Overview

This refactored implementation adds local site polling functionality to the `AIUtils::handle_sse_wait_with_timeout` method, with a cleaner separation of concerns where `poll_for_template()` is a generic polling utility and template-specific logic stays in the timeout handler.

## Key Changes Made

### 1. Modified `AIUtils::handle_sse_wait_with_timeout` Method

**File**: `includes/Core/Importer/Utils/AIUtils.php`

**Changes:**
- Added `$old_template_id` parameter to method signature
- Added `$is_local_site` detection from session data
- Implemented conditional polling logic within the waiting loop
- **NEW**: After polling, checks if specific template file exists before early return
- Template-specific logic remains in this method, not in polling function

**New Logic Flow:**
1. Check if all pages processed or credit cost available → Continue
2. Get session data including `isLocalSite` flag
3. If within timeout period:
   - **NEW**: If local site + template ID → Call generic polling function
   - **NEW**: Check if specific template file now exists after polling
   - **NEW**: Early return only if specific template file found
   - Otherwise → Send wait message and exit
4. If timeout exceeded → Continue processing

### 2. Refactored `poll_for_template` Generic Polling Function

**File**: `includes/Core/Importer/Utils/AIUtils.php`

**Functionality:**
- **REFACTORED**: Generic polling utility that processes ALL available templates
- **REMOVED**: Template-specific filtering logic
- **EXTRACTED**: Template processing code from `FullSiteImport::ai_poll_template`
- Makes GET request to `v2/ai/{process_id}/template` endpoint
- Saves ALL templates received in API response using `save_template_to_file`
- Returns boolean indicating polling success/failure (not template-specific)

### 3. Updated Method Calls

**Finalizer.php** (`includes/Core/Importer/Runners/Finalizer.php`):
- Added `$old_template_id` parameter to `handle_sse_wait_with_timeout` call
- Passes the current template ID being processed

**FullSiteImport.php** (`includes/Core/Importer/FullSiteImport.php`):
- Added `null` for `$old_template_id` parameter (no specific template context)
- Added `isLocalSite` flag handling in `import_ai_settings` method
- **REFACTORED**: `ai_poll_template` method now uses common `poll_for_template` function

### 4. Frontend Data Flow Updates

**Customizer.js** (`react-src/app/components/core/itemDetails/Packs/import-parts/Customizer.js`):
- **NEW**: Added `isLocalSite` flag to FormData in `handleNext()` function
- **NEW**: Uses `aiTemplates.isLocalSite` from AI workflow result

**Utils.js** (`react-src/app/components/core/AiContentSidebar/Utils.js`):
- **REMOVED**: `isLocalSite` flag transmission from `executeAIContentWorkflow`
- **REMOVED**: Session data update logic for `isLocalSite` in workflow
- **KEPT**: Session data update for `process_id` only

## Implementation Details

### Session Data Structure

```php
$session_data = [
    'session_id' => 'uuid',
    'isLocalSite' => true/false,  // Enables polling
    'process_id' => '12345',      // Required for polling
    'progress' => [
        'ai_content_time' => [
            'last_progress' => 50,
            'last_time' => timestamp
        ]
    ]
    // ... other session data
];
```

### Refactored Polling Logic Flow

```
1. handle_sse_wait_with_timeout called with template_id
2. Check if local site (session_data['isLocalSite'])
3. If local site + template_id:
   a. Get process_id from session
   b. Call poll_for_template(process_id, session_id, ai_page_ids)
   c. poll_for_template processes ALL templates from API
   d. Check if specific template file exists after polling
   e. If specific template file found:
      - Return true (continue processing)
   f. If not found:
      - Continue waiting
4. If not local site or no template_id:
   - Follow normal timeout logic
```

### API Integration

The polling uses the existing API endpoint pattern:
- **Endpoint**: `v2/ai/{process_id}/template`
- **Method**: GET
- **Response**: Same format as `ai_poll_template` method
- **Template Processing**: Saves ALL templates, checks specific file existence

## Benefits of Refactoring

1. **Cleaner Separation**: `poll_for_template()` is now a pure utility function
2. **Reusability**: Generic polling function can be used in other contexts
3. **Maintainability**: Template-specific logic is centralized in the timeout handler
4. **Proper Data Flow**: `isLocalSite` flows through UI components rather than workflow internals
5. **Code Reuse**: Extracted common template processing logic from multiple methods
6. **Single Responsibility**: Each function has a clear, focused purpose

## Usage Scenarios

### Scenario 1: Local Site with AI Content
- User imports template with AI content on local development site
- Finalizer processes template and encounters missing AI file
- `handle_sse_wait_with_timeout` called with template ID
- Generic polling processes all available templates
- Specific template file check determines if processing can continue
- Import continues without waiting if template found

### Scenario 2: Production Site
- Same import process on production site
- `isLocalSite` flag is false
- Normal timeout logic applies
- No polling performed

### Scenario 3: Local Site, No Specific Template
- Import process without specific template context
- `$old_template_id` is null
- No polling performed even on local site
- Normal timeout logic applies

## Future Enhancements

1. **Configurable Polling Interval**: Allow customization of polling frequency
2. **Polling Timeout**: Add separate timeout for polling operations
3. **Batch Template Polling**: Enhanced batch processing capabilities
4. **Polling Status Indicators**: Enhanced UI feedback during polling
5. **Polling Metrics**: Track polling performance and success rates
