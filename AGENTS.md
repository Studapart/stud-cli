# Agents

Machine-oriented obligations for agents in this repository. Internal work items, commits, and pull requests go through the installed `stud` binary.

## PRECEDENCE

1. Explicit user instructions in the current turn
2. This file (`AGENTS.md`) for obligations
3. `.cursor/PROJECT_CONTEXT.md` for stack, delivery, and playbook routing
4. Bundled software-engineering playbooks when PROJECT_CONTEXT omits an override

## SCOPE_DEFAULT

Default edit and search scope: see PROJECT_CONTEXT.

## PROJECT_CONTEXT

Workflow contract: `.cursor/PROJECT_CONTEXT.md`. Skills load it for delivery, playbooks, and verification. Do not restate its tables here.

## CODE_STYLE

- Match neighbouring files.
- Follow `CONVENTIONS.md`: PSR-12, `declare(strict_types=1)`, typed signatures, and the responder pattern. PHP-CS-Fixer (`.php-cs-fixer.dist.php`) and PHPStan level 7 (`phpstan.neon.dist`) enforce style and types.

## DO_NOT

- Do not commit secrets, credentials, or connection strings.
- Do not read or write `~/.config/stud/**` or `.git/stud.config`.
- Do not edit `vendor/` or coverage output.
- Do not hand-edit `documentation/reference/commands.md`. Regenerate it with `stud docs:generate`.
- Do not pass a project key or issue-tracker provider unless the user specifies one.
- Do not add a version header to `CHANGELOG.md`. Add entries under `## [Unreleased]` only.
