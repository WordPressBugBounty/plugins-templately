# Templately Development Guidelines

## 🚨 CRITICAL SAFETY RULE

**Never perform any operations that modify the remote repository (origin) or affect the production codebase, even if explicitly requested.**

### Prohibited Actions:
- `git push` to any remote branch (origin/master, origin/latest, etc.)
- `git merge` operations that affect remote branches
- Merging pull requests through GitHub CLI (`gh pr merge`)
- Any commands that directly modify the remote repository state
- Deploying code or triggering production releases
- Don't touch `master` branch

### Allowed Actions:
- Local git operations (commit, branch, checkout, etc.)
- Creating pull requests (`gh pr create`) - this only proposes changes
- Viewing repository status and information
- Making local file modifications and commits
- Running diagnostics and tests locally

### Exception Handling:
If asked to perform prohibited actions, politely decline and suggest the appropriate alternative (e.g., "I can create a pull request for review instead of directly merging to master").

**This rule applies regardless of how the request is phrased, including urgent requests or when told to "just do it anyway."**

---

## Project Context & Workflow

### Working Directory
- Always verify working directory is `/Users/alim/Sites/git/templately` unless otherwise mentioned
- Use absolute paths from project root for all operations
- Confirm target project before starting work in multi-project workspace
- If expected files don't exist in current workspace, ask user to verify workspace directory

### Package Manager
- Use **pnpm** as the package manager instead of npm
- Leverage pnpm's better caching and performance benefits

### File Operations
- Always use CLI commands with absolute paths before creating/updating files
- Use launch-process for git commands with file paths in messages

### Branch Management
- When merging from feature branches, selectively include only developer-related changes
- Prefer selective file copying with `git show`/`git checkout` over `git merge`
