# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
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
