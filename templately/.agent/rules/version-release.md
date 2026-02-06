# Version Bump & Release Management

## Version Bump Checklist
When updating the plugin version (e.g., from 3.3.4 to 3.4.0), update version numbers in these files:

### Core Plugin Files
1. **templately.php** (main plugin file)
   - Update `Version: X.X.X` in the plugin header comment

2. **includes/Plugin.php**
   - Update `public $version = 'X.X.X';` property

3. **package.json**
   - Update `"version": "X.X.X"` field

4. **languages/templately.pot**
   - Update `Project-Id-Version: Templately X.X.X` in the header

### Documentation Files
5. **readme.txt** (WordPress.org readme)
   - Update `Stable tag: X.X.X` in the header
   - Add new changelog entry at the top of the `== Changelog ==` section

6. **changelog.txt**
   - Add new changelog entry at the top

### Developer Files (if applicable)
7. **includes/Core/Developer/*.php** and **includes/API/DeveloperSettings.php**
   - Update `@since X.X.X` annotations in new features added in this version

## Changelog Format
```
= X.X.X - DD-MM-YYYY =
Added: [New features]
Revamped: [Major UI/UX changes]
Improved: [Enhancements]
Fixed: [Bug fixes]
Few minor bug fixes and improvements
```

## Version Bump Process
1. Verify current version across all files
2. Update all version references consistently
3. Add changelog entries with proper formatting
4. Run diagnostics to check for syntax errors
5. Test critical functionality
6. Commit with descriptive message: `feat: update version to X.X.X and enhance changelog`

---

# Commit Workflow

## Pre-Commit Verification
- Run diagnostics on modified files to check for syntax errors and warnings
- Verify changes align with requirements
- Use launch-process for git commands with file paths in messages

## Commit Process
- If no issues found, create git commit with descriptive message and brief success confirmation
- If issues found, provide specific details with file/line numbers and ask for instructions
- Don't commit critical errors but mention minor warnings and ask to proceed
- Use clear, descriptive commit messages that explain the changes made

---

# Automated Release Workflow

## Trigger Format
When given a changelog entry like:
```text
Templately v3.4.4
Fixed: Template import issue on Multisite.
Few minor bug fixes and improvements.
```

## Automated Release Steps

### Step 1: Parse Version Number
- Extract the version number from the changelog entry (e.g., "3.4.4")
- Validate format: X.X.X (semantic versioning)

### Step 2: Update Version References
Update all version references across the codebase according to the Version Bump Checklist above.

### Step 3: Format Changelog Entry
- Use the standard changelog format
- Add current date in DD-MM-YYYY format

### Step 4: Run Diagnostics
- Execute diagnostics on all modified files to check for syntax errors
- Files to check: templately.php, includes/Plugin.php, package.json, readme.txt, changelog.txt
- Verify no critical errors before proceeding

### Step 5: Execute pnpm release
- Run `pnpm release` command to generate build files and translation template
- **CRITICAL**: This step MUST run BEFORE creating the git commit
- Ask for confirmation before running this command

### Step 6: Create Git Commit
- Create a local git commit with descriptive message
- The commit should include all version updates, build files, and updated pot file
- Message format: `feat: update version to X.X.X and enhance changelog`
- Do NOT push to remote

## Important Constraints
- Follow the existing changelog format strictly
- Verify all version numbers are updated consistently across all 6 files
- Do NOT push to remote or merge - only commit locally
- Do NOT touch the `master` branch
- Ask for confirmation before running `pnpm release`
- **CRITICAL**: `pnpm release` MUST run BEFORE creating the git commit

## Verification Checklist
- [ ] Version number extracted correctly from changelog
- [ ] All 6 files updated with new version number
- [ ] Changelog entries added to readme.txt and changelog.txt with correct date format
- [ ] Diagnostics run with no critical errors
- [ ] `pnpm release` executed successfully (after confirmation)
- [ ] Build files and updated pot file are generated
- [ ] Git commit created locally with descriptive message
- [ ] No remote push or merge operations performed
