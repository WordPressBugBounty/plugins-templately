# API & Data Management

## API Refactoring Patterns
- Extract API logic into pure functions that return Promises
- Implement caching with search parameters as cache keys
- Fix pagination by calculating page numbers based on `startIndex` and `stopIndex`

## Centralized API Requests
- Create `makeApiRequest()` static functions in Helper class
- Include standard headers: site URL and API key
- Maintain consistent API request patterns across the application

## Race Condition Prevention
- Use `AbortController` to cancel previous ongoing requests
- Cancel ongoing requests before initiating new requests
- Implement proper cleanup in component unmounting

## Development API Configuration
- Use `TEMPLATELY_DEV_API` constant for API endpoint selection
- Simple boolean logic: `true` = dev server, `false`/`undefined` = live API
- Avoid complex state tracking for API endpoint management

## Error Handling in Async Functions
- Throw errors instead of returning them in async functions and promises
- This allows calling code to properly catch errors with `.catch()`
- Follow JavaScript/Promise best practices for error propagation

---

# Gateway Timeout Handling

## Polling Implementation
- Implement polling mechanism for 504 Gateway Timeout errors
- Use appropriate intervals between polling attempts
- Respect `AbortSignal` and clear intervals on component exit
- Implement sequential calls with delays between them
- Avoid fixed intervals; use dynamic timing based on response
- Implement proper cleanup and cancellation
