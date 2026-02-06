# AI Content Processing

## Content Validation
- Check if content is AI-generated before processing
- Implement proper content type detection
- Use traits over static classes for AI processing

## Class Name Processing
- Support indexed class names with dot notation (e.g., `'eb-feature-list-title.0'`)
- Implement flexible class name targeting
- Handle dynamic class name patterns

## AI Workflow Phases
- Use phased approach: backend session/pack download → parallel AI content generation → customizer integration
- Implement proper phase transitions and error handling
- Maintain state consistency across workflow phases

## Component Lifecycle
- Cancel all promises/intervals/timeouts when `aiconversation` component unmounts
- Prevent memory leaks in AI processing components
- Implement proper cleanup in `useEffect` cleanup functions

## Image Mapping
- Process both `img src` attributes and CSS `background-image` properties
- Ensure comprehensive image replacement functionality
- Handle various image reference patterns

## OpenAI API Integration
- OpenAI Responses API uses different response structure than Chat Completions API
- Function calls are in `output[]` array with `type: 'function_call'`
- Handle different response formats appropriately

## AI Process Retrieval
- Implement priority logic that uses `api_key` when available
- Fall back to `user_id` only when `api_key` is not provided/empty/no match
- Maintain proper fallback hierarchy for process identification
