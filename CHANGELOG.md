# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2025-11-06

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
