# Code Organization & Refactoring

## Separation of Concerns
- Move specific logic to separate files for better separation of concerns
- Use full OOP class encapsulation
- Maintain clear boundaries between different functionality areas

## Async Patterns
- Prefer async/await syntax over callback patterns for better readability
- Implement proper error handling in async operations
- Use consistent async patterns across the codebase

## PHP Traits
- PHP traits cannot be checked with `class_exists()`
- Static methods should be called through implementing classes, not directly on the trait
- Use traits for shared functionality across multiple classes

---

# Performance Optimization

## Masonry Grid Optimization
- `DefaultImageMasonryGrid` should re-render on selection changes
- `ImageMasonryGrid` should use boolean `hasSelectedImage` instead of `selectedImageId` to avoid unnecessary re-renders
- `useMasonry` hook should implement selective re-rendering logic for different component use cases
- Optimize grid performance for large image collections

## Build Script Optimization
- Use `.distignore` comment wrapper system to include/exclude development files instead of manual file copying
- Use `-dev` suffix for development zip filenames to distinguish from production builds
- Implement efficient build processes that minimize bundle size
- Redirect stdout to `/dev/null` while keeping stderr for errors
- Suppress verbose webpack output while preserving error information
