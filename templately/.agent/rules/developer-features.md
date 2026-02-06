# Templately Developer Features

## Developer Mode Configuration
- `TEMPLATELY_DEV_API` should work completely independently and only control API endpoint selection
- All developer functionality should only work when `TEMPLATELY_DEVELOPER_MODE` is enabled
- Remove legacy `TEMPLATELY_DEV` constant compatibility
- Keep `ApiManager` within Developer class structure but initialize independently of developer mode
- Contain `dev_api` property within `templatelyDeveloper` namespace

## Developer Interface Design
- Prefer vertical tab navigation with two-column layout to organize functionality into logical sections
- Implement transient deletion with tracking of `Database::set_transient()` calls
- Use developer mode access control and include confirmation dialogs
- Design modular architecture for developer tools

---

# Templately MCP Implementation

## MCP Architecture
- Use `@modelcontextprotocol/inspector` package for interactive testing
- Follow vertical tab navigation with two-column layout design pattern
- Design modular architecture for MCP functionality

## MCP Inspector Integration
- Prefer React Portal or Shadow DOM to isolate React instances
- Extract MCP components to `react-src/mcp/` directory with modular tab system
- Implement proper component isolation and state management

## MCP Interface Design
- Prefer collapsible tools panels for better organization
- Restrict model selection to only GPT nano models
- Display rendered responses by default with expandable raw JSON
- Maintain consistent UI patterns with other Templately interfaces
