# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- GitHub Action to automatically build and release `stud.phar` on new version tags.
- CI: Implement automatic PHPUnit tests and coverage reporting on Pull Requests.

### Changed

- Update `items:list` command to use `statusCategory` for JQL queries, providing more flexible filtering.

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
