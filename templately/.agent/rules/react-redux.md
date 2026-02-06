# React Hooks & Navigation

## Modal Management
- Create custom hooks for modal management with browser navigation prevention
- Implement navigation prevention during import operations
- Use specific alert messages for user guidance during critical operations

## Navigation Effects
- Only use `location` as a dependency in navigation-related `useEffect` hooks
- Avoid additional dependencies that may cause unexpected issues
- Keep navigation effects focused and minimal

## Navigation Blocking
- Use React Router's `useBlocker` hook in parent components where import status is available
- Implement navigation blocking during import operations
- Prefer parent component implementation over child component blocking

---

# Redux Implementation

## Saga Patterns
- Use centralized saga approach with specific action types
- Store data with loading states and error handling
- Implement proper error boundaries and fallback states

## State Structure
- Prefer nested state structure with `data`/`loading`/`error` objects
- Avoid flat state structure for complex data
- Maintain consistent state shape across reducers

## Data Preprocessing
- Use `useSelector` hooks to normalize numeric fields with `Number()` function
- Ensure consistent data types from API responses
- Handle type conversion at the selector level

---

# TypeScript Best Practices

## Logging
- Create wrapper functions for `console.log` that check `devMode` flags internally
- Centralize logging configuration and control
- Avoid direct console calls in production code

## Boolean Flag Handling
- Be explicit about default behavior in TypeScript
- Avoid patterns like `event.data.property !== false`
- Use clear, explicit boolean checks
