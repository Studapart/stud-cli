# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Prepend clickable Jira issue link to Pull Request description when using `stud submit` [SCI-1]
- Add `init` alias for `config:init` command [TPW-56]
- Add interactive shell auto-completion setup prompt at the end of `config:init` command [TPW-56]
- Automatically detect user's shell (bash or zsh) and provide installation instructions for shell completion [TPW-56]

### Changed
- Rename command `issues:search` to `items:search` for consistency with other item-related commands (alias `search` remains unchanged) [SCI-2]

## [2.1.5] - 2025-11-13

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
