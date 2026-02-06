# Testing Standards

## Test Organization
- Keep test files organized by removing unrelated files
- Place test configuration in main directory or config/ folder for Playwright pickup
- Ensure tests/ directory is in .gitignore to avoid committing test files

## Playwright Configuration
- Use Playwright configuration file at `.config/playwright.json` in project root
- Structure testing projects as standalone repositories over subdirectories
- Use pnpm instead of npm for better caching and performance

## Test Repository Management
- The local templately-playwright-tests repository is at `/Users/alim/Sites/git/templately-playwright-tests`
- When making test changes, edit files there, commit and push to GitHub
- Fetch the new version in the main Templately project to get updates
- Use `git add -f tests/playwright/` to force-add the Playwright test suite to version control
