<div align="center">
  <img src="src/resources/logo-300.png" alt="stud-cli Logo" width="200">
</div>

# Contributing to stud-cli

Thank you for your interest in contributing to **stud-cli**, the Jira & Git workflow streamliner by [Studapart](https://www.studapart.com/). This document explains how to propose changes, report issues, and align with the project’s standards.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [How Can I Contribute?](#how-can-i-contribute)
- [Development Setup](#development-setup)
- [Standards and Conventions](#standards-and-conventions)
- [Pull Request Process](#pull-request-process)
- [Documentation and ADRs](#documentation-and-adrs)
- [Questions and Discussions](#questions-and-discussions)

## Code of Conduct

This project adheres to the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold this code. Please report unacceptable behavior as described in that document.

## How Can I Contribute?

### Reporting Bugs

- Use the [Bug report](https://github.com/studapart/stud-cli/issues/new?template=bug_report.md) issue template.
- Include your environment (stud-cli version, PHP version, OS, installation method), steps to reproduce, and expected vs actual behavior.
- Do **not** include API tokens or secrets in issues.

### Suggesting Features

- Use the [Feature request](https://github.com/studapart/stud-cli/issues/new?template=feature_request.md) issue template.
- Keep in mind stud-cli’s scope: **Jira is read-only** (except for transitions and assignment in supported flows); server-side state is expected to be handled by Jira–GitHub/GitLab connectors. Proposals should fit this model when possible.

### Documentation

- Use the [Documentation](https://github.com/studapart/stud-cli/issues/new?template=documentation.md) issue template for README, CONVENTIONS, ADRs, or in-app help improvements.

### Pull Requests

- Follow the [Development Setup](#development-setup) and [Standards and Conventions](#standards-and-conventions) below.
- Keep PRs focused; reference any related issue.
- Ensure tests pass and static analysis is clean before submitting.

## Development Setup

1. **Requirements:** PHP 8.2+, `ext-xml`. See [README – System Requirements](README.md#system-requirements).
2. **Clone and install:**
   ```bash
   git clone https://github.com/studapart/stud-cli.git
   cd stud-cli
   composer install
   ```
3. **Run the CLI from source:**
   ```bash
   ./stud help
   ./stud config:init   # if you need a local config
   ```
4. **Run tests:**
   ```bash
   vendor/bin/phpunit
   ```
5. **Run static analysis:**
   ```bash
   vendor/bin/php-cs-fixer fix --dry-run --diff
   vendor/bin/phpstan analyse
   ```

For building a PHAR, see [README – Compiling to PHAR (using Box)](README.md#compiling-to-phar-using-box).

## Standards and Conventions

**All contributions must follow the project’s engineering conventions.** The single source of truth is:

- **[CONVENTIONS.md](CONVENTIONS.md)** – Coding standards, visibility, testing philosophy, quality metrics, output conventions, and CHANGELOG format.

Highlights:

- **Architecture:** PSR-12, SOLID, Responder pattern (see [ADR-005](documentation/adr-005-responder-pattern-architecture.md)), `object:verb` command naming ([ADR-006](documentation/adr-006-command-naming-convention.md)).
- **Quality:** Cyclomatic Complexity ≤ 10, CRAP Index ≤ 10, method size ≤ 40 lines, class size ≤ 400 lines; PHPStan level 7+.
- **Testing:** 100% coverage goal; “test the intent, not the text”; mock all service dependencies in unit tests.
- **CHANGELOG:** [Keep a Changelog](https://keepachangelog.com/) format; put breaking changes under `### Breaking`, not inline markers.

Run before every commit:

```bash
vendor/bin/php-cs-fixer fix
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

## Pull Request Process

1. Create a branch from `develop` (e.g. `feat/PROJ-123-add-thing` if you use Jira, or `feat/add-thing`).
2. Implement your change and add/update tests. Update README and/or CONVENTIONS if behavior or conventions change.
3. Add a new entry under `## [Unreleased]` in **CHANGELOG.md** only; do **not** add a new version header (releases are cut separately).
4. Ensure CI passes (PHP-CS-Fixer, PHPStan, PHPUnit).
5. Open a PR against `develop` with a clear title and description; link any related issue.
6. Address review feedback. Maintainers will merge when the PR is approved and CI is green.

## Documentation and ADRs

- **README.md** – User installation, configuration, and command reference; developer setup and PHAR build.
- **CONVENTIONS.md** – Coding and testing standards (required reading for code contributions).
- **documentation/** – Architecture Decision Records (ADRs) for design choices (e.g. Responder pattern, migrations, i18n), and [FEATURE_EVALUATION.md](documentation/FEATURE_EVALUATION.md) for a product-level overview of current features and potential gaps.

When changing behavior or architecture, consider adding or updating an ADR; use [documentation/adr-template.md](documentation/adr-template.md) for new ADRs.

## Questions and Discussions

- **GitHub Discussions:** [studapart/stud-cli discussions](https://github.com/studapart/stud-cli/discussions) for questions and ideas.
- **Security issues:** Do not open public issues. See [SECURITY.md](SECURITY.md).

Thank you for contributing to stud-cli.
