# WordPress Standards & Practices

## Translation & Internationalization
- Use appropriate translation functions like `__()` and `_e()` for proper internationalization
- Always wrap user-facing strings in translation functions

## REST API Usage
- Prefer custom Templately REST API endpoints over WordPress core endpoints
- Avoid fetching complete catalogs when only specific dependency information is needed
- Use dedicated API endpoints for dependency checking

## WordPress Packages
- Prioritize using `@wordpress/` packages for standard functionality:
  - `@wordpress/dom-ready` for DOM ready events
  - `@wordpress/api-fetch` for API calls
  - `@wordpress/components` for UI components
- Prefer WordPress packages over custom implementations

## WordPress API Fetch
- Use `addQueryArgs` which works with both URLs and paths
- Leverage apiFetch's built-in `parse` parameter instead of custom parsing logic
- Always use `apiFetch` instead of `fetch` for consistency with WordPress patterns

## User Verification
- Check `X-Templately-Verified` header in API responses
- When header is truthy, update user verification status using options utils:
  - Get 'user' option
  - Set `is_verified=true`
  - Save back to options

## Meta Data Management
- Store meta_ids in separate meta entries for targeted deletion during cleanup operations

## Attachment Processing
- For `attachment_type` fields in imports:
  - Only extract directly from XML `wp:attachment_type` elements
  - Set to `null` if not present in XML
  - Never derive from `post_mime_type`, file extensions, or other metadata

## Build Scripts
- Redirect stdout to `/dev/null` while keeping stderr for errors
- Suppress verbose webpack output while preserving error information
- Use `/tmp/` for temporary directories instead of current directory
- Exclude `react-src` from development builds when source maps provide sufficient debugging

---

# WordPress Multisite

## Network Detection
- Implement proper detection of subdomain vs subdirectory setups
- Use `SUBDOMAIN_INSTALL` constant or `is_subdomain_install()` function
- Handle both network configurations appropriately

## Network Admin Functionality
- Use dedicated files with WordPress hooks for separation of concerns
- Implement master developer constants for network-wide settings
- Add filter hooks in API methods to allow network admin overrides
- Maintain clear separation between single-site and network functionality
