# Project context

Workflow contract for software-engineering skills. Stack, delivery, and
playbook routing live here only.

## Repository profile

- Purpose: Terminal CLI that streamlines Jira, Linear, and Git work (work items, branches, conventional commits, pull requests, Confluence)
- Primary languages: PHP
- Runtime / framework: PHP >= 8.4.1, Symfony 8.1 components, jolicode/castor 1.8.1
- Package / task runner: Composer; tests and static analysis run as direct `vendor/bin` commands (Castor is the application runtime, not a test wrapper)

## Scope and paths

- Default edit/search scope: `src/`
- Other in-scope trees when explicitly requested: `tests/`, `documentation/`, `.github/`, `scripts/`, `castor.php`
- Generated or vendor trees to avoid: `vendor/`, `coverage-html/`, `coverage*`, `build/`, `documentation/reference/commands.md` (generated; refresh with `stud docs:generate`)

## Work items

- Tracker: Jira or Linear via `stud` (provider comes from stud configuration). GitHub Issues are external feedback only and are not the internal tracker
- Key pattern: `^[A-Za-z]+-\\d+$` (implementation uppercases, then matches `^([A-Z]+)-\\d+$`)
- Default project prefix: none — omit project and provider unless the user specifies them; stud reads `.git/stud.config`
- Work-item CLI: `stud`
- Schema / help: `echo '{}' | stud help --agent`
- Ingest / show: `echo '{"key":"KEY"}' | stud items:show --agent`
- Create: `echo '{"summary":"SUMMARY","description":"DESCRIPTION"}' | stud items:create --agent`
- Attach / replace: attach `echo '{"key":"KEY","files":["PATH"]}' | stud items:upload --agent`; replace `echo '{"key":"KEY","description":"DESCRIPTION"}' | stud items:update --agent`
- Docs show (wiki/Confluence): `echo '{"page":"PAGE"}' | stud confluence:show --agent`
- Work-item template: bundled
- Required tracker fields: `summary` on create. Project and provider are omitted unless the user specifies them

## Playbooks

No repository playbook overrides. Skills use the bundled software-engineering playbooks.

## Build and verification

- Install: `composer install`
- Lint / static analysis: `vendor/bin/php-cs-fixer fix --dry-run --diff` and `vendor/bin/phpstan analyse`
- Unit tests: `vendor/bin/phpunit`
- Integration / e2e: `vendor/bin/phpunit tests/Integration`; no end-to-end suite
- Coverage: whole-project, threshold 100%, VERIFICATION row Coverage
- Preferred task runner: none
- Complexity: none (no checked-in command fails on the threshold)
- Test frameworks:
  - unit: Unit tests (full)
  - integration: Integration / acceptance
  - functional: none
  - end-to-end: none
- Durable command table: `.cursor/VERIFICATION.md`

## Delivery workflow

- Default branch: `develop`
- preference: `stud`
- active: `stud`
- Commit tool: `stud`
- First PR command: `echo '{"labels":"AI-Generated,RFR","stageAll":true}' | stud submit --agent`
- Update existing PR command: `echo '{"labels":"AI-Generated,RFR"}' | stud submit --agent`
- Required PR labels: `AI-Generated`, `RFR`
- Review bot: none
- Delivery recipes: none
- When `active` is `stud` and `.cursor/hooks/deliver.sh` exists: that script is the only mutating delivery path (`commit` | `push` | `pr`).
- When `active` is `stud` and the script is absent: use Stud overlay recipes; apply the pre-mutation rule before any native `git`/`gh`.
- When `active` is `native`: do not load the Stud overlay. Use the tools already available. Prefer `rtk` when it is on PATH. Raw `git` and `gh` are allowed, not required.

## Documentation

- Docs index: `documentation/index.md`
- Architecture / ADR location: `documentation/`
- Coding conventions: `CONVENTIONS.md`

## Safety constraints

- Production database: forbidden for company-wide skills. Do not record connection strings, queries, or access recipes.
- Secrets / credential paths: `~/.config/stud/**`, `.git/stud.config`
- Changelog: must-update `CHANGELOG.md` (entries under `## [Unreleased]` only; do not add a version header)
- Other prohibited writes: `vendor/`, `coverage-html/`, `coverage*`, `phpunit-results.xml`, `.phpunit.result.cache`, `.php-cs-fixer.cache`
- Legacy policy: kernel default
- Project gotchas:
  - `origin/HEAD` is `develop`. `main` also exists.
  - Omit project key and `--provider` unless the user specifies them.
  - Whole-project line coverage must stay at 100%. CI comments the coverage summary and does not fail the job on the percentage.
  - New and modified code must stay within CC ≤ 10, CRAP ≤ 10, NPath ≤ 200, nesting depth ≤ 3, class ≤ 400 lines, method ≤ 40 lines. No command fails the build on those thresholds.
  - `documentation/reference/commands.md` is generated. After command-signature changes run `stud docs:generate` and `stud docs:check`.
  - Invoke the installed `stud` binary on `PATH`.
  - PHP platform is 8.4.1 (Composer `config.platform.php`).
  - `.cursor/tmp/` is local scratch and stays gitignored.

## Stack addenda

None.

## Sources

- `README.md` — purpose, docs index
- `composer.json` — PHP >= 8.4.1, Symfony 8.1, Castor 1.8.1, PHPUnit, PHP-CS-Fixer, PHPStan
- `CONTRIBUTING.md` — install, lint, test, default branch `develop`, changelog under `## [Unreleased]`
- `documentation/development/index.md` — `composer install`, PHPUnit, PHPStan, PHP-CS-Fixer, coverage command, `stud docs:generate` / `stud docs:check`
- `.github/workflows/tests.yml` — CI install, PHP-CS-Fixer dry-run, PHPStan, coverage comment (no percentage failure)
- `phpunit.xml.dist` — Unit Tests and Integration Tests suites; source `src/`
- `phpstan.neon.dist` — level 7, paths `src` and `castor.php`
- `src/Service/GitProjectConfigService.php` — issue-key pattern
- `documentation/features/work-items.md` — Jira or Linear via stud configuration
- `documentation/reference/commands.md` — `items:show`, `items:create`, `items:update`, `items:upload`, `confluence:show`, `stud help --agent`
- `documentation/setup/configuration.md` — `~/.config/stud/config.yml`, `.git/stud.config`
- `documentation/features/automation.md` — agent JSON, labels `AI-Generated,RFR`
- `documentation/index.md` — docs index and ADR location
- `CONVENTIONS.md` — 100% coverage, complexity and size thresholds, changelog format
- `SECURITY.md` — do not send tokens or credentials
- `.gitignore` — `vendor/`, coverage artifacts, `.cursor/*` with workflow exceptions
- user-confirmed — internal tracker is stud only; prefix comes from stud config; PR labels; coverage 100%; `active: stud` from `command -v stud`
