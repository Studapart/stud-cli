# Quality Audit Report — stud-cli

**Date**: 2026-03-11
**Scope**: All 118 PHP files in `src/`
**Standards**: CONVENTIONS.md, ADR-011 (Project Quality Metric Blueprint)

## Audit Environment

- PHP 8.4.17 with PCOV 1.0.12
- PHPUnit 11.5.50 — 1523 tests, 6401 assertions, **all passing**
- PHPStan Level 7 — **clean (0 errors)**
- PHP-CS-Fixer (PSR-12) — **clean (0 violations)**
- Code Coverage — **100% (Classes, Methods, Lines)**
- `declare(strict_types=1)` — **present in all 118 files**

---

## Violation Summary

| Metric | Threshold | Violations | At Threshold |
|--------|-----------|------------|--------------|
| Class Size | ≤ 400 code lines | **5** | 0 |
| Method Size | ≤ 40 code lines | **8** | 0 |
| Method Arguments | ≤ 4 | **40 methods** | 0 |
| Cyclomatic Complexity | ≤ 10 | 0 | **6** |
| CRAP Index | ≤ 10 | 0 | **6** |
| Nesting Depth | ≤ 3 | **6** | 0 |
| LCOM4 | ≤ 2 | 0 | **5** |
| Class Properties | ≤ 10 | 0 | 0 |
| `final` on injectable classes | forbidden | **5** | 0 |
| Handler direct I/O | forbidden | **1 file** | 0 |
| PSR-12 | clean | 0 | — |
| PHPStan Level 7 | clean | 0 | — |
| `declare(strict_types=1)` | required | 0 | — |

**Total unique files with at least one violation: 24**

---

## Detailed Violations by File

### `src/Service/GitRepository.php` (1385 total lines, 875 code lines)

- **Class size**: 875 code lines (max 400) — **VIOLATION**
- **Nesting depth**: `findRemoteBranchesByIssueKey` depth 5 (max 3) — **VIOLATION**
- **Method arguments**:
  - `ensureGitTokenConfigured`: 6 args (max 4) — **VIOLATION**
  - `warnGitTokenTypeMismatchIfOppositePresent`: 6 args (max 4) — **VIOLATION**
  - `promptAndSaveGitToken`: 6 args (max 4) — **VIOLATION**
- **Complexity**: `ensureGitProviderConfigured` CC=10, CRAP=10 — AT THRESHOLD

### `src/Handler/ItemCreateHandler.php` (891 total lines, 661 code lines)

- **Class size**: 661 code lines (max 400) — **VIOLATION**
- **Handler I/O**: 6 direct `$io->ask()`/`$io->choice()` calls — **VIOLATION** (ADR-005)
- **Complexity**: `handle` CC=10, CRAP=10 — AT THRESHOLD
- **Complexity**: `fillStandardFieldByName` CC=10, CRAP=10 — AT THRESHOLD
- **Method arguments** (10 methods):
  - `handle`: 11 args — **VIOLATION**
  - `buildBaseFields`: 6 args — **VIOLATION**
  - `resolveExtrasAndMergeIntoFields`: 11 args — **VIOLATION**
  - `promptForExtraRequiredFields`: 8 args — **VIOLATION**
  - `getPromptedValueForExtraField`: 11 args — **VIOLATION**
  - `getValueForStandardExtraField`: 9 args — **VIOLATION**
  - `promptIssueTypeValue`: 5 args — **VIOLATION**
  - `resolveStandardFieldsAndExtraRequired`: 11 args — **VIOLATION**
  - `fillStandardFieldByName`: 9 args — **VIOLATION**
  - `applyStandardFieldValue`: 8 args — **VIOLATION**

### `src/Service/HelpService.php` (601 total lines, 482 code lines)

- **Class size**: 482 code lines (max 400) — **VIOLATION**
- **Method size**: `getCommandMap` 232 code lines (max 40) — **VIOLATION**

### `src/Service/GithubProvider.php` (647 total lines, 415 code lines)

- **Class size**: 415 code lines (max 400) — **VIOLATION**

### `src/Service/GitLabProvider.php` (660 total lines, 405 code lines)

- **Class size**: 405 code lines (max 400) — **VIOLATION**
- **Method arguments**: `__construct` 5 args (max 4) — **VIOLATION**

### `src/Handler/UpdateHandler.php` (708 total lines, 397 code lines)

- **Method size**: `handle` 41 code lines (max 40) — **VIOLATION**
- **Nesting depth**: `isTestEnvironmentByBacktrace` depth 4 (max 3) — **VIOLATION**
- **Complexity**: `displayChangelog` CC=10, CRAP=10 — AT THRESHOLD
- **Method arguments**: `__construct` 11 args (max 4) — **VIOLATION**

### `src/Handler/BranchRenameHandler.php` (453 total lines, 326 code lines)

- **Method arguments**:
  - `__construct`: 8 args (max 4) — **VIOLATION**
  - `handle`: 5 args (max 4) — **VIOLATION**
  - `performRename`: 5 args (max 4) — **VIOLATION**
  - `handlePostRenameActions`: 5 args (max 4) — **VIOLATION**

### `src/Handler/BranchCleanHandler.php` (617 total lines, 363 code lines)

- **Method arguments**: `__construct` 5 args (max 4) — **VIOLATION**

### `src/Handler/SubmitHandler.php` (478 total lines, 332 code lines)

- **Method size**: `runSubmitPreflight` 41 code lines (max 40) — **VIOLATION**
- **Nesting depth**: `handleExistingPr` depth 4 (max 3) — **VIOLATION**
- **Method arguments**:
  - `__construct`: 8 args (max 4) — **VIOLATION**
  - `createPullRequest`: 5 args (max 4) — **VIOLATION**

### `src/Handler/DeployHandler.php` (212 total lines)

- **Method size**: `handle` 42 code lines (max 40) — **VIOLATION**
- **Nesting depth**: `handle` depth 4 (max 3) — **VIOLATION**

### `src/Handler/InitHandler.php` (314 total lines, 213 code lines)

- **Method size**: `handle` 79 code lines (max 40) — **VIOLATION**

### `src/Handler/CommitHandler.php` (212 total lines, 170 code lines)

- **Method arguments**:
  - `__construct`: 5 args (max 4) — **VIOLATION**
  - `handle`: 5 args (max 4) — **VIOLATION**

### `src/Handler/ItemStartHandler.php` (243 total lines, 177 code lines)

- **Method arguments**: `__construct` 6 args (max 4) — **VIOLATION**

### `src/Handler/ItemTakeoverHandler.php` (366 total lines, 236 code lines)

- **Method arguments**: `__construct` 7 args (max 4) — **VIOLATION**

### `src/Handler/ReleaseHandler.php` (172 total lines, 120 code lines)

- **Method arguments**:
  - `__construct`: 6 args (max 4) — **VIOLATION**
  - `handle`: 5 args (max 4) — **VIOLATION**

### `src/Service/VersionCheckService.php` (172 total lines, 115 code lines)

- **Method arguments**: `__construct` 6 args (max 4) — **VIOLATION**

### `src/Service/UpdateFileService.php` (187 total lines, 103 code lines)

- **Method arguments**: `replaceBinary` 5 args (max 4) — **VIOLATION**

### `src/Service/DescriptionFormatter.php` (239 total lines, 171 code lines)

- **Complexity**: `processOneSectionToTitleAndContent` CC=10, CRAP=10 — AT THRESHOLD
- **Method arguments**:
  - `flushCurrentListItem`: 5 args (max 4) — **VIOLATION**
  - `appendNonCheckboxLine`: 6 args (max 4) — **VIOLATION**

### `src/Service/FileSystem.php` (480 total lines, 224 code lines)

- **Nesting depth**: `chmod` depth 4 (max 3) — **VIOLATION**

### `src/Service/JiraAdfHelper.php` (107 total lines)

- **Method size**: `plainTextToAdf` 50 code lines (max 40) — **VIOLATION**

### `src/Service/MigrationExecutor.php` (123 total lines)

- **Method size**: `executeMigrations` 44 code lines (max 40) — **VIOLATION**

### `src/Service/MigrationRegistry.php` (261 total lines, 127 code lines)

- **Method size**: `discoverMigrations` 41 code lines (max 40) — **VIOLATION**

### `src/View/PageViewConfig.php` (347 total lines, 224 code lines)

- **Complexity**: `renderContent` CC=10, CRAP=10 — AT THRESHOLD
- **Nesting depth**: `extractValue` depth 4 (max 3) — **VIOLATION**

### `src/Handler/BranchAction.php`

- **`final` keyword**: `final` used on Handler enum — **VIOLATION**

### `src/Responder/AgentCommandResponder.php`

- **`final` keyword**: `final` used on Responder class — **VIOLATION**

### `src/Service/AgentModeSchemaGenerator.php`

- **`final` keyword**: `final` used on Service class — **VIOLATION**

### `src/Service/DtoSerializer.php`

- **`final` keyword**: `final` used on Service class — **VIOLATION**

### `src/Service/MarkdownHelper.php`

- **`final` keyword**: `final` used on Service class — **VIOLATION**

### `src/DTO/PullRequestComment.php`

- **Method arguments**: `__construct` 5 args (max 4) — **VIOLATION**

### `src/DTO/PullRequestData.php`

- **Method arguments**: `__construct` 5 args (max 4) — **VIOLATION**

### `src/DTO/WorkItem.php`

- **Method arguments**: `__construct` 11 args (max 4) — **VIOLATION**

### `src/Response/ConfigShowResponse.php`

- **Method arguments**: `__construct` 7 args (max 4) — **VIOLATION**
- **LCOM4**: 2 — AT THRESHOLD

### `src/Response/ConfigValidateResponse.php`

- **Method arguments**: `__construct` 6 args (max 4) — **VIOLATION**

### `src/Response/ItemCreateResponse.php`

- **Method arguments**: `__construct` 5 args (max 4) — **VIOLATION**

### `src/Response/ItemListResponse.php`

- **Method arguments**: `__construct` 5 args (max 4) — **VIOLATION**

### `src/Response/PrCommentsResponse.php`

- **Method arguments**: `__construct` 6 args (max 4) — **VIOLATION**

---

## LCOM4 At-Threshold (= 2)

- `src/Handler/ReleaseHandler.php` — LCOM4=2
- `src/Responder/ItemShowResponder.php` — LCOM4=2
- `src/Response/ConfigShowResponse.php` — LCOM4=2
- `src/Service/AgentModeHelper.php` — LCOM4=2
- `src/Service/JiraHtmlConverter.php` — LCOM4=2

---

## Complexity Watch List (CC ≥ 8, no violations)

| CC | CRAP | Class | Method |
|----|------|-------|--------|
| 10 | 10 | `ItemCreateHandler` | `handle` |
| 10 | 10 | `ItemCreateHandler` | `fillStandardFieldByName` |
| 10 | 10 | `UpdateHandler` | `displayChangelog` |
| 10 | 10 | `DescriptionFormatter` | `processOneSectionToTitleAndContent` |
| 10 | 10 | `GitRepository` | `ensureGitProviderConfigured` |
| 10 | 10 | `PageViewConfig` | `renderContent` |
| 9 | 9 | `CommitHandler` | `commitWithJiraPrompt` |
| 9 | 9 | `ItemCreateHandler` | `applyStandardFieldValue` |
| 9 | 9 | `ItemCreateHandler` | `applyOptionalFieldsFromCreatemeta` |
| 9 | 9 | `SubmitHandler` | `runSubmitPreflight` |
| 9 | 9 | `SubmitHandler` | `handleExistingPr` |
| 9 | 9 | `UpdateHandler` | `handle` |
| 9 | 9 | `Migration_GitTokenFormat` | `up` |
| 9 | 9 | `AgentModeHelper` | `readFromStdin` |
| 9 | 9 | `ChangelogParser` | `parse` |
| 9 | 9 | `ChangelogParser` | `getSectionTitle` |
| 9 | 9 | `CommentBodyParser` | `consumeTable` |
| 9 | 9 | `HelpService` | `buildOptionsLines` |
| 9 | 9 | `HelpService` | `buildUsageSectionLines` |
| 9 | 9 | `JiraHtmlConverter` | `toMarkdown` |
| 8 | 8 | `BranchCleanHandler` | `hasOpenPullRequest` |
| 8 | 8 | `BranchCleanHandler` | `buildPrMap` |
| 8 | 8 | `BranchListHandler` | `buildPrMap` |
| 8 | 8 | `BranchRenameHandler` | `handle` |
| 8 | 8 | `InitHandler` | `handle` |
| 8 | 8 | `InitHandler` | `resolveTokenFromLegacy` |
| 8 | 8 | `ItemCreateHandler` | `resolveExtrasAndMergeIntoFields` |
| 8 | 8 | `ItemCreateHandler` | `getValueForStandardExtraField` |
| 8 | 8 | `ConfigShowResponder` | `respond` |
| 8 | 8 | `FileSystem` | `chmod` |
| 8 | 8 | `HelpService` | `extractHelpLinesFromReadme` |
| 8 | 8 | `MarkdownToAdfConverter` | `convertBlock` |
| 8 | 8 | `UpdateFileService` | `verifyHash` |

---

## Compliant Files (no violations)

The following 83 files have zero violations across all metrics:

- `src/Attribute/AgentOutput.php` — compliant
- `src/Config/SecretKeyPolicy.php` — compliant
- `src/DTO/BranchListRow.php` — compliant
- `src/DTO/Filter.php` — compliant
- `src/DTO/Project.php` — compliant
- `src/DTO/ValidationResult.php` — compliant
- `src/Enum/OutputFormat.php` — compliant
- `src/Exception/AgentModeException.php` — compliant
- `src/Exception/ApiException.php` — compliant
- `src/Exception/GitException.php` — compliant
- `src/Handler/BranchListHandler.php` — compliant
- `src/Handler/CacheClearHandler.php` — compliant
- `src/Handler/CommitUndoHandler.php` — compliant
- `src/Handler/ConfigShowHandler.php` — compliant
- `src/Handler/ConfigValidateHandler.php` — compliant
- `src/Handler/FilterListHandler.php` — compliant
- `src/Handler/FilterShowHandler.php` — compliant
- `src/Handler/FlattenHandler.php` — compliant
- `src/Handler/ItemListHandler.php` — compliant
- `src/Handler/ItemShowHandler.php` — compliant
- `src/Handler/ItemTransitionHandler.php` — compliant
- `src/Handler/PleaseHandler.php` — compliant
- `src/Handler/PrCommentHandler.php` — compliant
- `src/Handler/PrCommentsHandler.php` — compliant
- `src/Handler/ProjectListHandler.php` — compliant
- `src/Handler/SearchHandler.php` — compliant
- `src/Handler/StatusHandler.php` — compliant
- `src/Handler/SyncHandler.php` — compliant
- `src/Migrations/AbstractMigration.php` — compliant
- `src/Migrations/GlobalMigrations/Migration202501150000001_GitTokenFormat.php` — compliant
- `src/Migrations/MigrationInterface.php` — compliant
- `src/Migrations/MigrationScope.php` — compliant
- `src/Responder/BranchListResponder.php` — compliant
- `src/Responder/ConfigShowResponder.php` — compliant
- `src/Responder/ConfigValidateResponder.php` — compliant
- `src/Responder/ErrorResponder.php` — compliant
- `src/Responder/FilterListResponder.php` — compliant
- `src/Responder/FilterShowResponder.php` — compliant
- `src/Responder/ItemCreateResponder.php` — compliant
- `src/Responder/ItemListResponder.php` — compliant
- `src/Responder/ItemShowResponder.php` — compliant
- `src/Responder/PrCommentsResponder.php` — compliant
- `src/Responder/ProjectListResponder.php` — compliant
- `src/Responder/SearchResponder.php` — compliant
- `src/Response/AbstractResponse.php` — compliant
- `src/Response/AgentJsonResponse.php` — compliant
- `src/Response/BranchListResponse.php` — compliant
- `src/Response/FilterListResponse.php` — compliant
- `src/Response/FilterShowResponse.php` — compliant
- `src/Response/ItemShowResponse.php` — compliant
- `src/Response/ProjectListResponse.php` — compliant
- `src/Response/ResponseInterface.php` — compliant
- `src/Response/SearchResponse.php` — compliant
- `src/Service/AgentModeIoInterface.php` — compliant
- `src/Service/CanConvertToAsciiDocInterface.php` — compliant
- `src/Service/CanConvertToMarkdownInterface.php` — compliant
- `src/Service/CanConvertToPlainTextInterface.php` — compliant
- `src/Service/ChangelogParser.php` — compliant
- `src/Service/ColorHelper.php` — compliant
- `src/Service/CommentBodyParser.php` — compliant
- `src/Service/ConfigValidator.php` — compliant
- `src/Service/GitProviderInterface.php` — compliant
- `src/Service/HtmlConverterInterface.php` — compliant
- `src/Service/JiraHtmlConverter.php` — compliant
- `src/Service/JiraService.php` — compliant
- `src/Service/Logger.php` — compliant
- `src/Service/MarkdownToAdfConverter.php` — compliant
- `src/Service/ProcessFactory.php` — compliant
- `src/Service/ResponderHelper.php` — compliant
- `src/Service/ThemeDetector.php` — compliant
- `src/Service/TranslationService.php` — compliant
- `src/View/Column.php` — compliant
- `src/View/Content.php` — compliant
- `src/View/DefinitionItem.php` — compliant
- `src/View/Section.php` — compliant
- `src/View/TableBlock.php` — compliant
- `src/View/ViewConfigInterface.php` — compliant
- `src/config/app.php` — compliant
- `src/config/colours.php` — compliant
- `src/config/constants.php` — compliant
- `src/repack/logo.php` — compliant

---

## Key Observations

1. **No CC/CRAP hard violations** — all 693 methods are at or below threshold. However, 6 methods sit exactly at CC=10, meaning any added branching will create a violation.
2. **Class size is the dominant violation** — 5 classes exceed 400 code lines, with `GitRepository.php` at 875 lines (2.2× the limit).
3. **Method argument count is the most widespread violation** — 40 methods across 20+ files exceed the 4-argument limit. Most are constructors that inject many dependencies.
4. **Constructor arguments** account for the majority of argument violations. These indicate classes with too many dependencies (SRP concern) but are often acceptable for DI containers. Parameter objects or class decomposition are the remedies.
5. **`ItemCreateHandler`** is the most violated file in the codebase: class size, argument counts (10 methods), handler I/O, and two methods at CC threshold.
6. **Method size violations are moderate** — 8 methods exceed 40 lines, with `HelpService::getCommandMap` being an extreme outlier at 232 lines.
7. **All foundational checks pass**: `declare(strict_types=1)`, PSR-12, PHPStan Level 7, 100% test coverage.
