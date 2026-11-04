<div align="center">
  <img src="src/resources/logo-300.png" alt="stud-cli Logo" width="160">
</div>

# Security Policy

## Supported Versions

We release patches for security vulnerabilities for the following versions of stud-cli:

| Version | Supported          |
|---------|--------------------|
| 5.x     | :white_check_mark: (current line, PHP 8.5) |
| 4.x     | :white_check_mark: (PHP 8.4) |
| 3.x     | :x: (frozen after 4.x GA; pin last 3.x release at own risk) |
| < 3.0   | :x:                |

In general, the current line is stud-cli 5.x and requires PHP 8.5. stud-cli 4.x is the PHP 8.4 line. When PHP 8.6 is released, that line will be stud-cli 6.x. The 3.x line is frozen — see [migrating 3.x → 4.x](documentation/setup/migrating-3x-to-4x.md). We encourage users to upgrade to the [latest release](https://github.com/studapart/stud-cli/releases).

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

If you believe you have found a security issue in stud-cli:

1. **Report privately** by opening a [GitHub Security Advisory](https://github.com/studapart/stud-cli/security/advisories/new) for this repository, or by emailing the maintainers (see repository contacts / GitHub organization).
2. Include a clear description of the issue, steps to reproduce, and the impact you think it has.
3. Allow a reasonable time for a fix before any public disclosure.

### What to expect

- We will acknowledge your report and work to confirm the issue.
- We will keep you informed about the status of the fix and any release that addresses the vulnerability.
- We credit reporters in security advisories and release notes when they agree to it.

### Scope

- **In scope:** stud-cli codebase (PHP), dependency chain relevant to stud-cli, and documented configuration (e.g. token storage, config file permissions).
- **Out of scope:** General Jira, GitHub, or GitLab security policies; misconfiguration by users (e.g. exposed tokens outside stud-cli); third-party services we integrate with.

### Sensitive data

- **Never** send API tokens, passwords, or real credentials when reporting. Use placeholders and minimal reproduction steps.

Thank you for helping keep stud-cli and its users safe.
