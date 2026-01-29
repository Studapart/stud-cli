# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Optimized
- Optimize PR lookups for branch management commands to reduce GitHub API calls [SCi-45]
  - `branches:list` and `branches:clean` now fetch all PRs once (1-2 API calls) instead of per-branch calls (20-40 calls)
  - Added `GithubProvider::getAllPullRequests()` method with pagination support (handles 100+ PRs)
  - Added `GithubProvider::hasNextPage()` helper to parse GitHub API Link headers for pagination
  - `BranchListHandler` and `BranchCleanHandler` now use cached PR map for efficient lookups
  - Graceful fallback to per-branch API calls if bulk fetch fails
  - Maintains backward compatibility and handles edge cases (fork PRs, missing repo info)
  - 100% test coverage for new code paths

### Added
- Display technical error details from Git and API errors alongside user-friendly messages [SCI-41]
  - Created `GitException` and `ApiException` classes with `getTechnicalDetails()` method
  - `GitRepository::run()` now captures Git error output and throws `GitException` with technical details
  - `JiraService` methods now throw `ApiException` with API response body and status code
  - `GithubProvider` methods now throw `ApiException` with API response details
  - Added `Logger::errorWithDetails()` method to display both user-friendly and technical error messages
  - All handlers now catch `GitException` and `ApiException` and display both messages using `errorWithDetails()`
  - Technical details are truncated to 500 characters to avoid overwhelming output
  - Error messages maintain backward compatibility with existing translations

### Fixed
- Fix branch deletion failures due to stale remote-tracking references [SCI-44]
  - `GitRepository::deleteBranch()` now accepts optional `$remoteExists` parameter to handle stale refs
  - When remote branch doesn't exist, stale remote-tracking refs are pruned before deletion
  - Added `GitRepository::deleteBranchForce()` method for force delete fallback
  - Added `GitRepository::pruneRemoteTrackingRefs()` method to remove stale refs
  - `BranchCleanHandler` and `DeployHandler` now handle deletion failures gracefully with force delete fallback
  - Branch deletion now works correctly even when remote branch was deleted externally (e.g., via GitHub UI)

### Added
- Add branch management commands: `branches:list` and `branches:clean` [SCI-40]
  - `stud branches:list` (alias `bl`) lists all local branches with status (merged, stale, active-pr, active)
  - `stud branches:clean` (alias `bc`) interactively cleans up merged/stale branches with `--quiet` option for non-interactive mode
  - Add `--clean` option to `stud deploy` to clean up merged branches after deployment
  - GitRepository methods: `getAllLocalBranches()`, `isBranchMergedInto()`, `getAllRemoteBranches()`
  - GithubProvider: `findPullRequestByBranch()` now accepts `state` parameter ('open', 'closed', 'all')
  - GithubProvider: `findPullRequestByBranchName()` helper method for finding PRs by branch name
  - BranchListHandler and BranchCleanHandler following ADR pattern
  - Protected branches (develop, main, master) are never deleted by `branches:clean`
  - All user-facing text uses TranslationService
  - Comprehensive unit and integration tests with 100% coverage

## [3.2.0] - 2026-01-13

### Added
- Add PHP extension validation and improve README with system requirements and token setup guide [SCI-39]
  - Added extension check in `JiraHtmlConverter::toMarkdown()` to validate XML extension before HTML to Markdown conversion
  - Added bootstrap extension validation listener in `castor.php` to check for required PHP extensions before command execution
  - Added `ext-xml` requirement to `composer.json` to document the dependency
  - Added System Requirements section to README.md with PHP version and extension requirements
  - Added PHP extension installation instructions for Ubuntu/Debian, Fedora/RHEL, and macOS
  - Added Token Setup Guide subsection to README.md Configuration section with detailed Jira and GitHub token instructions
  - Added troubleshooting entries for missing XML extension and token permission errors
  - Updated GitHub Actions workflow to explicitly include xml extension
  - Improved error messages in `SubmitHandler` to provide actionable guidance when XML extension is missing
  - Extension check gracefully falls back to original content when XML extension is unavailable
  - Extension check is skipped for whitelisted commands (config:init, help, main, cache:clear) to allow users to see help/init

## [3.1.0] - 2026-01-06

### Fixed
- Fix TypeError in Logger::askHidden() when Symfony returns null [SCI-38]
  - Changed Logger::askHidden() return type from `string` to `?string` to match Symfony's behavior
  - Changed Logger::ask() return type from `string` to `?string` to match Symfony's behavior (same root cause)
  - Symfony's askHidden() and ask() can return null when user presses Enter without input, cancels prompt (Ctrl+C), or runs in non-interactive mode
  - Updated method docblocks to document when null may be returned
  - InitHandler already handles null correctly via `?:` operator, so no changes needed there
  - Updated ItemTransitionHandler to handle null return from ask()
  - Added unit tests to verify null return scenarios for both ask() and askHidden()
- Fix missing commands and options in HelpService documentation [SCI-37]
  - Added `items:transition` command (alias: `tx`) with optional `[<key>]` argument to help documentation
  - Added `branch:rename` command (alias: `rn`) with `--name` option and optional `[<branch>]` and `[<key>]` arguments to help documentation
  - Added `--sort` option (shortcut: `-s`) to `items:list` command in help documentation
  - Fixed README.md: corrected submit command alias from `stud sub` to `stud su` to match actual implementation
  - Enhanced usage example generation to skip optional arguments (wrapped in `[...]`) in examples while keeping them in command signatures
  - Added support for `<value>`, `<name>`, and `<branch>` argument types in option examples
  - Added translation key `help.option_branch_rename_name` to all supported languages
  - Updated HelpServiceTest to include new commands in test coverage

## [3.0.0] - 2025-12-18

### Added
- Add HTML-to-Markdown conversion for Pull Request descriptions in `stud submit` command [SCI-36]
  - PR descriptions are automatically converted from Jira's HTML format to Markdown for better readability on GitHub
  - Conversion preserves formatting: headings, lists, code blocks, and links are correctly converted
  - Conversion handles Jira-specific HTML artifacts (broken tags, HTML entities, spans) gracefully
  - If conversion fails, PR description falls back to raw HTML (existing behavior preserved)
  - CLI display (`stud sh`) continues to use plain text description (no changes to CLI output)
  - Added `league/html-to-markdown` package dependency
  - Added verbose logging for conversion success/failure scenarios
  - Full test coverage with unit tests for conversion method
- Add `stud items:takeover` command (alias: `stud to`) to take over issues from other users [SCI-34]
  - Command prioritizes remote branches over local branches
  - If multiple remote branches found, command lists them and lets user choose
  - If single remote branch found, command auto-selects it after confirmation
  - Command switches to existing local branch if found
  - Command creates local tracking branch from remote if remote exists but local doesn't
  - Command shows branch status (behind/ahead/sync) compared to remote and develop
  - Command warns if branch is based on different base branch than expected
  - Command warns if local branch has diverged from remote (has local commits)
  - Command pulls from remote (with rebase) if behind and no local commits
  - If no branches found, command prompts user to start fresh (calls items:start)
  - If user is already on target branch, command skips checkout and only checks status
  - Command displays comprehensive success message with branch status
  - Added new GitRepository methods: `switchBranch()`, `switchToRemoteBranch()`, `findBranchesByIssueKey()`, `getBranchStatus()`, `isBranchBasedOn()`, `pullWithRebase()`
  - Updated ItemStartHandler to handle existing branches (switches instead of failing)
  - Added translation keys for takeover handler in all supported languages
  - Full test coverage with unit tests following project conventions
  - Updated README.md with command documentation and usage examples
- Add `stud branch:rename` command (alias: `stud rn`) to rename branches with optional Jira key or explicit name [SCI-35]
  - Command accepts optional branch name parameter (defaults to current branch)
  - Command accepts optional Jira key parameter to regenerate branch name (like `stud start`)
  - Command accepts `--name` option for explicit branch name (no prefix added)
  - Command validates new branch name doesn't already exist (local and remote)
  - Command validates explicit branch name follows Git naming rules
  - Command handles local-only branches (renames local, informs about missing remote)
  - Command handles remote-only branches (prompts to rename remote only, default yes)
  - Command checks branch synchronization before renaming remote
  - Command prompts to rebase if local is behind remote (default yes, bypass if `--quiet`)
  - Command handles rebase failures gracefully with helpful suggestions
  - Command renames both local and remote branches when both exist
  - Command detects associated Pull Request (if GithubProvider available)
  - Command attempts to update PR head branch via GitHub API (may not be supported by GitHub)
  - Command adds comment to PR explaining the rename
  - Command handles PR update failures gracefully (warns but continues)
  - Command shows confirmation message with current/new names and actions
  - Command asks for confirmation (default yes, bypass if `--quiet`)
  - Command suggests creating PR if none exists after rename
  - Added new GitRepository methods: `renameLocalBranch()`, `renameRemoteBranch()`, `getBranchCommitsAhead()`, `getBranchCommitsBehind()`, `canRebaseBranch()`
  - Added new GithubProvider method: `updatePullRequestHead()`
  - Added translation keys for branch rename handler in all supported languages
  - Full test coverage with unit tests following project conventions
  - Updated README.md with command documentation and usage examples

## [2.9.0] - 2025-12-15

### Added
- Create Response classes and ViewConfig infrastructure for Responder pattern [SCI-30]
  - Created Response base classes: `ResponseInterface` and `AbstractResponse`
  - Created specific Response classes: `FilterShowResponse`, `ItemListResponse`, `ItemShowResponse`, `ProjectListResponse`, `SearchResponse`
  - All Response classes use static factory methods (`success()` and `error()`) and are final (DTOs)
  - Created ViewConfig infrastructure: `ViewConfigInterface`, `TableViewConfig`, `PageViewConfig`
  - Created supporting value objects: `Column`, `DefinitionItem`, `Section`, `Content`
  - `TableViewConfig` supports conditional column visibility (e.g., Priority column) and column formatters
  - `PageViewConfig` supports sections with definition lists and content blocks
  - All classes include `declare(strict_types=1);` and explicit type hints
  - Comprehensive unit tests with 100% coverage
  - Updated README.md with Responder pattern architecture documentation

### Changed
- Refactor simple table-based handlers to use Responder pattern [SCI-31]
  - Refactored `FilterShowHandler`, `SearchHandler`, `ItemListHandler`, and `ProjectListHandler` to return Response objects instead of handling IO directly
  - Created corresponding Responder classes: `FilterShowResponder`, `SearchResponder`, `ItemListResponder`, `ProjectListResponder`
  - All Responders use `TableViewConfig` for consistent table rendering with conditional column visibility
  - Updated `castor.php` task functions to orchestrate Handler → Responder flow
  - Handlers now contain pure domain logic (no IO dependencies)
  - Responders handle all presentation logic (sections, error messages, table rendering)
  - All handler tests refactored to test pure domain logic (no IO mocking)
  - Comprehensive responder tests with 100% coverage
  - Maintains existing behavior: error handling, empty state handling, verbose output, priority column conditional display
- Refactor complex handlers (ItemShowHandler, StatusHandler) to use Responder pattern [SCI-32]
  - Refactored `ItemShowHandler` to return `ItemShowResponse` instead of handling IO directly
  - Created `ItemShowResponder` with definition list display and description section parsing
  - Extracted description parsing logic to `DescriptionFormatter` service for reusability
  - `DescriptionFormatter` handles section parsing, content sanitization, and checkbox list formatting
  - `ItemShowResponder` displays issue details using definition lists and formatted description sections
  - All description parsing behavior preserved (section dividers, checkbox lists, content formatting)
  - Updated `castor.php` `items_show()` function to use Handler → Responder pattern
  - All handler tests refactored to test pure domain logic (no IO mocking)
  - Comprehensive responder and service tests with 100% coverage
  - `StatusHandler` evaluated and kept as-is: it's a simple dashboard view (~60 lines) that combines Jira, Git, and local status in a unique format that doesn't fit the standard table/page pattern. Refactoring would add complexity without architectural benefit.

## [2.8.0] - 2025-12-12

### Added
- Add `stud items:transition` command to transition Jira work items [SCI-27]
  - New command `stud items:transition` (alias: `stud tx`) to transition a Jira work item to a different status
  - Command accepts optional `<workItemKey>` argument
  - When key is not provided, command attempts to detect from current Git branch name
  - When key is detected from branch, command asks for user confirmation before proceeding
  - When key is not detected, command prompts user to enter a Jira work item key
  - Command validates key format and shows error for invalid format
  - Command fetches and displays all available transitions for the issue (no filtering)
  - Command allows user to select a transition from available options
  - Command applies the selected transition to the issue
  - Command does NOT auto-assign the issue (only transitions)
  - Command does NOT cache transition IDs (always shows all available transitions)
  - Command handles errors gracefully (issue not found, no transitions, API errors)
  - Added translation keys for transition handler in all supported languages
  - Full test coverage with unit tests following project conventions
- Added `--sort` option (alias `-s`) to `stud items:list` command to sort results by Key or Status (case-insensitive)

### Added
- Add Priority and Jira URL columns to stud filters:show command [SCI-28]
  - Enhanced `stud filters:show` command to display Priority and Jira URL columns
  - Priority column is conditionally displayed only when at least one issue has a priority assigned
  - Column order: Key, Status, Priority (conditional), Description, Jira URL
  - Priority column shows priority name (e.g., "High", "Medium", "Low") when available, empty string when not available (when column is visible)
  - Jira URL column displays full URL in format: {JIRA_URL}/browse/{key}
  - Added priority field to WorkItem DTO
  - Updated JiraService to fetch and map priority field from Jira API
  - Added translation keys for priority, description, and jira_url in all supported languages
  - Full test coverage with unit tests following project conventions
- Add filters:list command to display Jira filters [SCI-26]
  - New command `stud filters:list` (alias: `stud fl`) to list all available Jira filters
  - Displays filters in a table format with Name and Description columns
  - Filters are sorted by name in ascending order (case-insensitive)
  - Handles empty results and API errors gracefully
  - Added translation keys for filter list handler in all supported languages
  - Full test coverage with unit tests following project conventions
- Add filters:show command to retrieve Jira issues by filter name [SCI-25]
  - New command `stud filters:show <filterName>` (alias: `stud fs`) to retrieve issues from saved Jira filters
  - Generates JQL query `filter = "<filterName>"` and reuses existing search infrastructure
  - Updated SearchHandler to return int (0 for success, 1 for error) for consistency with other handlers
  - Added translation keys for filter show handler in all supported languages
  - Full test coverage with unit tests following project conventions

## [2.7.1] - 2025-12-08

### Added
- Implement mandatory config check on command execution [SCI-23]
  - Added global pre-execution check that aborts non-whitelisted commands when config file is missing
  - Commands `config:init`, `help`, `main`, `update`, and `cache:clear` are whitelisted and work without config
  - Displays clear warning message instructing users to run `stud config:init` when config is missing
  - Commands exit with error code 1 when config is required but missing
- Harden stud config:init by disabling external checks and adding system locale detection [SCI-24]
  - Excluded config:init command from global update checking mechanism to prevent network delays
  - Added system locale detection from LC_ALL and LANG environment variables
  - Falls back to 'en' if locale detection fails or language is not supported
  - Ensures config:init completes quickly without external network calls

### Fixed
- Resolve infinite loop/segfault on first run by fixing circular dependency in config initialization [SCI-22]
  - Updated `_get_translation_service()` to check config file existence before calling `_get_config()`
  - Prevents circular dependency when config file doesn't exist during first run
  - Defaults to 'en' locale when config file is missing, avoiding infinite recursion

## [2.7.0] - 2025-12-01

### Added
- Implement automatic Semantic Versioning (SemVer) bumping in 'stud release' [SCI-21]
  - Added `--major` (`-M`), `--minor` (`-m`), and `--patch` (`-p`) flags for automatic version bumping
  - Version argument is now optional when using SemVer flags
  - Default behavior: patch increment when no flags or version provided
  - Mutually exclusive flag validation to prevent conflicts
  - Version calculation based on current version from `composer.json`

## [2.6.2] - 2025-12-01

### Fixed
- Fix `JiraService::assignIssue` unassigning tickets by implementing required `/myself` lookup [SCI-19]
  - Added `getCurrentUserAccountId()` method that calls `/rest/api/3/myself` endpoint to retrieve the authenticated user's accountId
  - Updated `assignIssue()` to use the retrieved accountId instead of passing `null` when assigning to current user
  - Implemented caching to ensure the `/myself` API call is executed only once per application lifecycle

## [2.6.1] - 2025-11-28
### Added
- Integrate PHP-CS-Fixer and PHPStan for automated code quality enforcement [SCI-18]
  - PHP-CS-Fixer configuration (`.php-cs-fixer.dist.php`) enforces PSR-12 code style
  - PHPStan configuration (`phpstan.neon.dist`) set to Level 7 minimum for strict type checking
  - Both tools added as require-dev dependencies in `composer.json`

## [2.6.0] - 2025-11-28

### Added
- Add comprehensive Project Quality Metric Blueprint to CONVENTIONS.md [SCI-18]
  - Complexity metrics: Cyclomatic Complexity ≤ 10, CRAP Index ≤ 10, NPath ≤ 200, Nesting Depth ≤ 3
  - Cohesion metrics: LCOM4 ≤ 2
  - Size metrics: Class ≤ 400 lines, Method ≤ 40 lines
  - Signature metrics: Class Properties ≤ 10, Method Arguments ≤ 4
  - Type safety rules: strict typing, explicit type hints, DocBlock requirements
- Add `stud flatten` command (alias: `stud ft`) to automatically squash all `fixup!` and `squash!` commits into their target commits [SCI-16]
  - Performs non-interactive rebase with autosquash, eliminating manual rebase editing
  - Fails gracefully if working directory has uncommitted changes
  - Warns user that history will be rewritten (requires `stud please` push afterward)
- Add `stud cache:clear` command (alias: `stud cc`) to clear the update check cache file [SCI-17]
  - Forces a version check on the next command execution without waiting 24 hours
  - Useful for maintainers and developers testing the update workflow
  - Reports success if cache file is deleted or already clear

### Changed
- Replace custom `slugify()` method in `ItemStartHandler` with Symfony's `AsciiSlugger` directly [SCI-12]
  - Uses the same underlying component that Castor's `slug()` function uses, providing consistency and simplicity
  - Removes dependency on Castor container initialization

## [2.5.1] - 2025-11-28

### Changed
- Refactor AI.md into four-phase development protocol (Investigate and Plan, Execution and Documentation, Project Integrity and Commit, Summarize and Conclude) with enforced planning, complexity assessment, and 100% code coverage verification [SCI-15]
- Update CONVENTIONS.md with measurable code quality thresholds: maximum Cyclomatic Complexity of 10 per method and maximum CRAP Index of 10 per class [SCI-15]
- Add dependency isolation rules to CONVENTIONS.md: all service dependencies (Handlers, Providers, Repositories) must be mocked in unit tests; real service instances are forbidden [SCI-15]
- Refactor `stud update` verification to use GitHub API digest property instead of external checksum files [SCI-14]
  - Removed logic for fetching external `.sha256`, `.sha256sum`, and `checksums.txt` files
  - Now extracts digest directly from the PHAR asset's JSON object in GitHub API response
  - Calculates SHA-256 hash of downloaded file and compares against API digest

### Added
- Add user override option for `stud update` verification failures [SCI-14]
  - When hash mismatch or missing digest is detected, user is prompted to continue or abort
  - User can override verification failure to proceed with installation (useful for hotfix/dev scenarios)
  - Default behavior is to abort on verification failure for security

## [2.5.0] - 2025-11-27

### Added
- Add `stud pr:comment` (alias `pc`) command to post comments to active Pull Requests with STDIN support for automation workflows [SCI-10]
- Add dynamic 'In Progress' transition support to `stud items:start` command with project-specific caching [SCI-11]
  - Automatically assigns issue to current user and transitions to 'In Progress' when enabled
  - Interactive transition selection on first run per project
  - Caches transition ID in `.git/stud.config` for future use
  - Adds `JIRA_TRANSITION_ENABLED` configuration flag in `stud config:init`

### Changed
- Improve `stud items:show` command to display description in dedicated sections with automatic text sanitization and divider detection [SCI-8]

### Fixed
- Remove brittle statusCategory filtering from `stud items:start` transition lookup to show all available transitions instead of filtering by 'in_progress' status category [SCI-13]

## [2.4.1] - 2025-11-25

### Removed
- Rem coverage folder that was mistakenly add by AI.

## [2.4.0] - 2025-11-25

### Added
- Add `stud help <command>` to display detailed help for a specific command, with support for command aliases [SCI-7]
- Add `--info` (`-i`) option to `stud update` command to preview changelog without downloading [SCI-6]

## [2.3.0] - 2025-11-24

### Added
- Add `--labels` option to `stud submit` command to apply labels to Pull Requests with interactive validation and creation of missing labels [SCI-5]
- Add `--draft` (`-d`) option to `stud submit` command to create Draft Pull Requests on GitHub [SCI-4]
- Display CHANGELOG diff and flag breaking changes during `stud update` [SCI-3]

### Fixed
- Fix CHANGELOG.md to document missing changes in version 2.1.5

## [2.2.0] - 2025-11-20

### Added
- Prepend clickable Jira issue link to Pull Request description when using `stud submit` [SCI-1]
- Add `init` alias for `config:init` command [TPW-56]
- Add interactive shell auto-completion setup prompt at the end of `config:init` command [TPW-56]
- Automatically detect user's shell (bash or zsh) and provide installation instructions for shell completion [TPW-56]

### Breaking
- Rename command `issues:search` to `items:search` for consistency with other item-related commands (alias `search` remains unchanged) [SCI-2]

## [2.1.5] - 2025-11-13

### Fixed
- Fix segfault issue during PHAR execution by moving version check to a function called after initialization
- Fix segfault issue by implementing a more defensive approach to version checking

## [2.1.4] - 2025-11-13

## [2.1.3] - 2025-11-13

## [2.1.2] - 2025-11-13

### Fixed
- Fix "Undefined constant" error during PHAR build by replacing global `const` declarations with `define()` function calls [TPW-55]

## [2.1.1] - 2025-11-13

### Fixed
- Fix "Undefined constant" error during PHAR build by replacing global `const` declarations with `define()` function calls [TPW-55]

## [2.1.0] - 2025-11-13

### Added
- Implement internationalization (i18n) support with translation files for 8 languages: English (en), French (fr), Spanish (es), Dutch (nl), Russian (ru), Greek (el), Afrikaans (af), and Vietnamese (vi) [TPW-53]
- Add language selection prompt to `stud config:init` command (defaults to English)
- Add `TranslationService` to handle all user-facing messages
- All user-facing strings (success, error, warning, note, text, section messages) are now translatable
- Translation files are PHAR-safe and located in `src/resources/translations/`
- Add proactive, non-blocking version check at application bootstrap that silently checks for updates every 24 hours and displays a warning message at the end of command execution if a new version is available [TPW-54]
- Add `VersionCheckService` to handle version checking logic with file-based caching

### Changed
- Refactor all handlers to use `TranslationService` for output messages
- Update `stud commit` command to display prompts in user's selected language while maintaining English commit messages (per Conventional Commits standard)
- All error messages and user feedback now respect the user's language preference from config

## [2.0.1] - 2025-11-13

### Fixed
- Fixed `stud update` command failing with "Could not find composer.json" when run from PHAR. The command now uses the `APP_REPO_SLUG` constant (baked into the PHAR during build) instead of reading composer.json at runtime.

## [2.0.0] - 2025-11-13

### Added
- chore: Docs create convention for development standards [TPW-49] (#25)
### Changed
- Refactored entire codebase to align with new CONVENTIONS.md for testability and reliability. All injectable service classes are no longer final, complex private methods are now protected for direct testing, and all unit tests now test intent (behavior, return values, exceptions) rather than specific output text strings.
- chore: Docs Update and rename GEMINI markdown to AI markdown [TPW-50] (#26)
- fix: Update command must use composer name [TPW-48] (#24)

## [1.1.3] - 2025-11-13
### Added

- Add `stud update` command (alias: `stud up`) to check for and install new versions of the tool automatically.
- Add `getLatestRelease()` method to `GithubProvider` service for fetching the latest GitHub release.
- Add "Updating" section to README.md with instructions for using `stud update`.

### Changed

- Update README.md installation guide to recommend user-owned installation path (`~/.local/bin/`) for seamless updates without `sudo`.
- Move global installation method (`/usr/local/bin/`) to "Alternative Installation" section with note about requiring `sudo` for updates.

## [1.0.4] - 2025-11-12

### Added

- GitHub Action to automatically build and release `stud.phar` on new version tags.
- CI: Implement automatic PHPUnit tests and coverage reporting on Pull Requests.
- Add `getRepositoryOwner()` and `getRepositoryName()` methods to `GitRepository` service for dynamic repository detection.

### Changed

- Update `items:list` command to use `statusCategory` for JQL queries, providing more flexible filtering.
- **[BREAKING]** Remove `GIT_REPO_OWNER` and `GIT_REPO_NAME` from global configuration - repository details are now automatically detected from git remote.
- Refactor `config:init` command to no longer prompt for repository owner and name.
- Update `submit` command to dynamically detect repository owner and name from git remote at runtime.

### Fixed

- Fix `submit` command GitHub API error by properly formatting the `head` parameter with repository owner prefix (e.g., `owner:branch`) when creating pull requests.

## [1.0.0] - 2025-11-06

### Added
- Implement `release` and `deploy` commands to automate the release process.
- Centralize application metadata in `composer.json`.
- Add `dump-config` script to generate `config/app.php`.
- Add `config/constants.php` to define global constants.

### Changed
- Refactor `castor.php` to use the new constants.
- Update `ItemStartHandler` to use the new `GitRepository` methods.

### Added
- Create PHPUnit test suite for the application.
- Refactor commands to be more testable.
- Add `--message` (`-m`) flag to `commit` command to bypass the interactive prompter.
