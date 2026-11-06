# Verification profile

## Meta

- Source: `CONTRIBUTING.md`, `documentation/development/index.md`, `.github/workflows/tests.yml`, `phpunit.xml.dist`, `phpstan.neon.dist`, `phpmd.xml`, `CONVENTIONS.md`, `.cursor/PROJECT_CONTEXT.md`
- Preferred task runner: none
- Confirmed: yes
- Last reviewed: 2026-11-04
- Coverage: whole-project, threshold 100%, row Coverage
- Complexity: `vendor/bin/phpmd analyze --no-progress src castor.php`
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
| Coverage (changed files / gate) | `mkdir -p .cursor/tmp && php -dpcov.enabled=1 -dpcov.directory=. -dpcov.exclude="~vendor~" -dzend.assertions=1 ./vendor/bin/phpunit --coverage-text --coverage-clover .cursor/tmp/phpunit-clover.xml` |
| Complexity | `vendor/bin/phpmd analyze --no-progress src castor.php` |
| CRAP | `php scripts/check-crap.php .cursor/tmp/phpunit-clover.xml crap.baseline.txt` |

## Definition of done

Ordered commands that must exit 0 before delivery.

1. `vendor/bin/php-cs-fixer fix --dry-run --diff`
2. `vendor/bin/phpstan analyse`
3. `mkdir -p .cursor/tmp && php -dpcov.enabled=1 -dpcov.directory=. -dpcov.exclude="~vendor~" -dzend.assertions=1 ./vendor/bin/phpunit --coverage-text --coverage-clover .cursor/tmp/phpunit-clover.xml`
4. `php scripts/check-crap.php .cursor/tmp/phpunit-clover.xml crap.baseline.txt`
5. `vendor/bin/phpmd analyze --no-progress src castor.php`

## Defaults for agents

Follow `playbooks/core/verification-profile.md` in the software-engineering plugin.

## Notes

- The coverage command exits 0 when tests pass. Read the summary and keep Classes, Methods, and Lines at 100% before delivery. CI posts that summary as a pull-request comment and does not fail the job on the percentage. The command enables assertions so the two `assert()` lines in `MarkdownToAdfConverter` are executed. `zend.assertions` cannot be turned on from `phpunit.xml` when the process starts at `-1`.
- PHPMD fails cyclomatic complexity above 10, NPath above 200, method length above 40, class length above 400, more than 4 arguments, and more than 10 properties. `phpmd.baseline.xml` records violations that already existed. A new method or a new rule on a method fails. PHPMD's baseline ignores the whole rule on a listed method, so a higher value on a method already in the baseline does not fail by itself. Do not add a `phpmd.baseline.xml` entry to silence a violation.
- CRAP above 10 fails from the Clover file written by the coverage command. `crap.baseline.txt` records the score of methods that were already above 10. A higher score fails, and the failure names that recorded score. A method that is not listed fails above 10. Nesting depth ≤ 3 and LCOM4 ≤ 2 stay review notes. They are not definition-of-done commands.
- Data-carrier constructors under `src/DTO/`, `src/Response/`, and `src/Guard/DTO/`, the value objects `src/Guard/CommandContext.php`, `src/Guard/CommandGuardResult.php`, `src/Handler/GlobalInit/GlobalInitPromptContext.php`, and `src/Service/UpdateInstallContext.php`, and the named constructors `BranchSwitchResponse::error`, `FilterShowResponse::success`, `ItemCreateResponse::success`, `ItemListResponse::success`, and `PrCommentsResponse::success` are exempt when they only carry or forward data. Existing exempt constructors are in the baseline. A new one carries `@SuppressWarnings(PHPMD.ExcessiveParameterList)` and no other suppression. An injectable service is not exempt.
- PHPUnit `defaultTimeLimit` is 30 seconds. `APP_ENV=test` is set by `phpunit.xml.dist`.
