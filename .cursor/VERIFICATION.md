# Verification profile

## Meta

- Source: `CONTRIBUTING.md`, `documentation/development/index.md`, `.github/workflows/tests.yml`, `phpunit.xml.dist`, `phpstan.neon.dist`, `CONVENTIONS.md`, `.cursor/PROJECT_CONTEXT.md`
- Preferred task runner: none
- Confirmed: yes
- Last reviewed: 2026-10-23
- Coverage: whole-project, threshold 100%, row Coverage
- Complexity: none
- Test frameworks: unit (`Unit tests (full)`), integration (`Integration / acceptance`); functional none; end-to-end none

## Commands

Exact command lines for this repository only.

| Need | Command |
|------|---------|
| Install deps | `composer install` |
| Lint / static analysis (touched scope) | `vendor/bin/php-cs-fixer fix --dry-run --diff` then `vendor/bin/phpstan analyse` |
| Unit tests (full) | `vendor/bin/phpunit` |
| Unit tests (focused) | `vendor/bin/phpunit {PATH}` |
| Integration / acceptance | `vendor/bin/phpunit tests/Integration` |
| E2E / UI | N/A |
| All-tests shortcut | `vendor/bin/phpunit` |
| Coverage (changed files / gate) | `php -dpcov.enabled=1 -dpcov.directory=. -dpcov.exclude="~vendor~" ./vendor/bin/phpunit --coverage-text` |
| Complexity | N/A |

## Definition of done

Ordered commands that must exit 0 before delivery.

1. `vendor/bin/php-cs-fixer fix --dry-run --diff`
2. `vendor/bin/phpstan analyse`
3. `php -dpcov.enabled=1 -dpcov.directory=. -dpcov.exclude="~vendor~" ./vendor/bin/phpunit --coverage-text`

## Defaults for agents

Follow `playbooks/core/verification-profile.md` in the software-engineering plugin.

## Notes

- The coverage command exits 0 when tests pass. Read the summary and keep Classes, Methods, and Lines at 100% before delivery. CI posts that summary as a pull-request comment and does not fail the job on the percentage.
- CC ≤ 10, CRAP ≤ 10, NPath ≤ 200, nesting depth ≤ 3, class ≤ 400 lines, and method ≤ 40 lines are required by `CONVENTIONS.md`. No checked-in command fails on those thresholds.
- PHPUnit `defaultTimeLimit` is 30 seconds. `APP_ENV=test` is set by `phpunit.xml.dist`.
