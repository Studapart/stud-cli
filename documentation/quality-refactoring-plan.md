# Quality Refactoring Plan — stud-cli

**Date**: 2026-03-11
**Source**: quality-audit-report.md (Phase 1)
**Constraint**: Behaviour-preserving only. No new features. All existing tests must pass after each step.

---

## Execution Order

Actions are ordered by dependency: shared/extracted code first, then consumers. Low-risk items first to build confidence.

---

## Group 1: Trivial Fixes (no structural changes)

### 1.1 Remove `final` from injectable classes

**Files**: 4 files
**Violations addressed**: `final` keyword on Services/Responders
**Strategy**: Remove the `final` keyword. One-line change per file.
**SOLID**: Open/Closed Principle — classes should be open for extension.
**Risk**: Very low
**Reviewed**: `BranchAction.php` is a constants-holder (value object), not an injectable service — `final` is correct per CONVENTIONS. Removed from this list.

| File | Change |
|------|--------|
| `src/Responder/AgentCommandResponder.php` | Remove `final` from class |
| `src/Service/AgentModeSchemaGenerator.php` | Remove `final` from class |
| `src/Service/DtoSerializer.php` | Remove `final` from class |
| `src/Service/MarkdownHelper.php` | Remove `final` from class |

### 1.2 Fix nesting depth — guard clauses

**Strategy**: Replace nested conditionals with early returns/continues and method extraction.

#### `src/Service/FileSystem.php::chmod` — depth 4 → max 2

Extract the permission-resolution logic into a helper. Use early returns.

#### `src/Handler/UpdateHandler.php::isTestEnvironmentByBacktrace` — depth 4 → max 3

Flatten with early continue in the loop body.

#### `src/View/PageViewConfig.php::extractValue` — depth 4 → max 3

Extract inner nested logic into a private helper method.

#### `src/Handler/SubmitHandler.php::handleExistingPr` — depth 4 → max 3

Extract the inner branch into a helper method.

#### `src/Handler/DeployHandler.php::handle` — depth 4, also 42 lines

Extract `cleanupReleaseBranch()` and `deleteLocalBranch()` private methods. The branch-delete logic with nested try/catch becomes flat with guard clauses and early returns.

---

## Group 2: Method Size Fixes (extract helper methods)

### 2.1 `src/Service/HelpService.php::getCommandMap` — 232 lines → new class

**Violation**: Class 482 lines (max 400), method 232 lines (max 40)
**Strategy**: Extract the command map data array into `src/Service/CommandMap.php` with a single static `all()` method. `HelpService::getCommandMap()` becomes a one-liner delegate.
**New class**: `App\Service\CommandMap` (~240 lines, pure data — no logic)
**Result**: HelpService drops to ~250 code lines. CommandMap is data-only, exempt from complexity metrics.
**SOLID**: SRP — separate data from presentation logic.
**Risk**: Very low (data extraction, no logic change)

### 2.2 `src/Handler/InitHandler.php::handle` — 79 lines → ~12 lines

**Violation**: Method 79 lines (max 40)
**Strategy**: Extract wizard steps into focused protected methods:
- `loadExistingConfig()` (~5 lines)
- `buildConfigFromPrompts($existing)` (~30 lines, orchestrates prompt methods)
- `applyMigrationVersion(&$config, $existing)` (~10 lines)
- `saveConfig($config)` (~10 lines)

`handle()` becomes a 12-line orchestrator calling these methods.
**SOLID**: SRP — each method handles one wizard phase.
**Risk**: Low

### 2.3 `src/Service/JiraAdfHelper.php::plainTextToAdf` — 50 lines → ~17 lines

**Violation**: Method 50 lines (max 40)
**Strategy**: Extract `buildDocNode()` and `buildParagraphNode()` private static helpers to eliminate repeated ADF array literals. Collapse defensive branches into ternary fallbacks.
**Risk**: Very low

### 2.4 `src/Service/MigrationExecutor.php::executeMigrations` — 44 lines → ~14 lines

**Violation**: Method 44 lines (max 40)
**Strategy**: Extract `runSingleMigration()` and `handleMigrationFailure()` private methods. Main loop becomes a simple foreach with try/catch.
**Risk**: Low

### 2.5 `src/Service/MigrationRegistry.php::discoverMigrations` — 41 lines → ~22 lines

**Violation**: Method 41 lines (max 40)
**Strategy**: Extract `loadMigrationFromFile()` private method. Guard clauses replace nested continues.
**Risk**: Low

### 2.6 `src/Handler/SubmitHandler.php::runSubmitPreflight` — 41 lines → ~35 lines

**Violation**: Method 41 lines (max 40)
**Strategy**: Extract validation checks into a helper. This is borderline; small extraction suffices.
**Risk**: Very low

### 2.7 `src/Handler/UpdateHandler.php::handle` — 41 lines → ~35 lines

**Violation**: Method 41 lines (max 40)
**Strategy**: Extract the version comparison block into a helper method.
**Risk**: Very low

---

## Group 3: Class Decomposition — GitRepository (highest priority)

### 3.1 Extract `GitSetupService` from `GitRepository`

**Violation**: GitRepository 875 code lines (max 400), 3 methods with 6 args, ensureGitProviderConfigured CC=10
**Strategy**: Extract all interactive configuration methods that accept `$io`/`Logger`/`TranslationService` into a new `GitSetupService`.

**Methods moved to `App\Service\GitSetupService`** (~300 code lines):
- `ensureBaseBranchConfigured()` + `validateConfiguredBaseBranch()` + `resolveDefaultBaseBranchQuiet()` + `promptAndSaveBaseBranch()` + `getBaseBranch()` + `detectBaseBranch()`
- `ensureGitProviderConfigured()`
- `ensureGitTokenConfigured()` + `getGitTokenKeysForProvider()` + `resolveGitTokenFromConfig()` + `warnGitTokenTypeMismatchIfOppositePresent()` + `promptAndSaveGitToken()`

**Dependencies**: `GitSetupService(GitRepository, FileSystem, Logger, TranslationService)` — 4 constructor args. `$io` (SymfonyStyle) remains a method parameter since it's a runtime object created per-command.
**SOLID**: SRP — GitRepository handles git commands, GitSetupService handles interactive configuration.
**Risk**: Medium (many consumers reference these methods on GitRepository)
**Reviewed**: Constructor kept to 4 args. `$io` passed at call time, not constructor, due to Symfony Console lifecycle.

### 3.2 Extract `GitBranchService` from `GitRepository`

**Strategy**: Extract branch query and branch mutation methods.

**Methods moved to `App\Service\GitBranchService`** (~250 code lines):
- `findBranchesByIssueKey()` + `findLocalBranchesByIssueKey()` + `findRemoteBranchesByIssueKey()`
- `getAllLocalBranches()` + `getAllRemoteBranches()`
- `getBranchStatus()` + `getBranchCommitsAhead()` + `getBranchCommitsBehind()`
- `isBranchMergedInto()` + `isBranchBasedOn()` + `canRebaseBranch()`
- `renameLocalBranch()` + `renameRemoteBranch()`

**Dependencies**: `GitBranchService(GitRepository)`
**SOLID**: SRP — Branch-level operations separate from low-level git commands.
**Risk**: Medium

### 3.3 Fix nesting depth in `findRemoteBranchesByIssueKey` — depth 5 → max 2

**Strategy**: Extract `fetchRemoteRefsForPrefix()` and `parseRemoteBranchNames()` private helpers. Guard clauses eliminate all nesting.

### 3.4 Fix 6-arg methods in GitSetupService

**Strategy**: `$logger` and `$translator` become constructor deps. `$io` stays as method param (runtime object). Method signatures:
- `ensureBaseBranchConfigured(SymfonyStyle $io, bool $quiet): string` — 2 args
- `ensureGitProviderConfigured(SymfonyStyle $io, bool $quiet): string` — 2 args
- `ensureGitTokenConfigured(string $providerType, SymfonyStyle $io, array $globalConfig, bool $quiet): ?string` — 4 args
- `warnGitTokenTypeMismatchIfOppositePresent(array $projectConfig, array $globalConfig, array $keys, string $providerType): void` — 4 args (uses `$this->logger`, `$this->translator`)
- `promptAndSaveGitToken(string $providerType, array $projectConfig, array $globalConfig, array $keys): ?string` — 4 args (uses `$this->logger`)

**Reviewed**: All methods ≤ 4 args after migration.

**Result after Group 3**: GitRepository ~325 lines, GitBranchService ~250 lines, GitSetupService ~300 lines. All under 400.

---

## Group 4: Class Decomposition — ItemCreateHandler

### 4.1 Create `App\DTO\ItemCreateInput` parameter object

**Violation**: `handle()` has 11 args
**Strategy**: New DTO to hold all create-item input data.

```php
final class ItemCreateInput {
    public function __construct(
        public readonly string $projectKey,
        public readonly string $summary,
        public readonly string $issueTypeName,
        public readonly bool $typeExplicitlyProvided = false,
        // ... remaining fields as optional
    ) {}
}
```

**Risk**: Low (new class, no existing code changed yet)

### 4.2 Create `App\DTO\StandardFieldContext` parameter object

**Violation**: 8+ methods pass the same 6-9 args (projectKey, issueTypeId, summary, descriptionAdf, assignee, parentKey, etc.)
**Strategy**: New DTO to consolidate repeated field context.
**Result**: Eliminates argument bloat from 8+ methods at once.
**Risk**: Low

### 4.3 Extract `App\Service\DurationParser`

**Violation**: ItemCreateHandler class size
**Strategy**: Move `parseOriginalEstimateToSeconds()`, `durationUnitToSeconds()`, `durationUnitToSecondsMultiplier()`, and `DURATION_UNIT_MULTIPLIERS` to a new focused utility class (~40 lines).
**Risk**: Very low (self-contained utility)

### 4.4 Extract `App\Service\IssueFieldResolver`

**Violation**: ItemCreateHandler 661 code lines, CC threshold methods
**Strategy**: Move all pure field-resolution logic (no `$io`):
- `resolveIssueTypeName()`, `resolveIssueTypeId()`, `buildBaseFields()`
- `resolveStandardFieldsAndExtraRequired()`, `fillStandardFieldByName()`, `applyStandardFieldValue()`
- `defaultAssigneeWhenFieldPresent()`, `getRequiredFieldIdsFromMeta()`, `applyOptionalFieldsFromCreatemeta()`
- `findOptionalFieldKey()`, `getCreatePayloadFieldKey()`, `getExtraRequiredFieldsList()`

**Dependencies**: `IssueFieldResolver(JiraService, DurationParser)`
**Result**: ~250 code lines, fully testable without I/O mocking.
**Risk**: Medium

### 4.5 Extract `App\Service\ItemCreateInputCollector`

**Violation**: Handler direct I/O (6 `$io->ask()`/`$io->choice()` calls)
**Strategy**: Move all interactive input methods:
- `resolveProjectKey()`, `ensureProjectExists()`, `resolveSummary()`
- `promptForExtraRequiredFields()`, `getPromptedValueForExtraField()`
- `chooseIssueTypeInteractively()`, `promptDescriptionValue()`
- `getValueForStandardExtraField()`, `promptIssueTypeValue()`, `valueForDescriptionExtraField()`

Called from `castor.php` task layer, not from Handler. Handler becomes I/O-free per ADR-005.
**Dependencies**: `ItemCreateInputCollector(GitRepository, JiraService, TranslationService)`
**Result**: ~150 code lines.
**Risk**: Medium (changes castor.php wiring)

### 4.6 `resolveExtrasAndMergeIntoFields(11 args)` — decompose

**Violation**: 11 arguments — tied for worst in codebase
**Strategy**: This orchestration method gets split. Its input-collection half moves to `ItemCreateInputCollector`, its field-resolution half moves to `IssueFieldResolver`. With `StandardFieldContext` DTO, neither half needs more than 4 args.
**Reviewed**: Previously missing from plan. Added per Phase 3 review.

### 4.7 Slim `ItemCreateHandler::handle()`

**Result**: Handler becomes ~80-100 code lines. `handle(ItemCreateInput)` has 1 arg. CC drops from 10 to ~5. Zero `$io` calls.

---

## Group 5: Method Argument Fixes (constructors)

### Strategy for constructor argument violations

Most constructor violations are DI constructors with 5-11 dependencies. Two approaches:

**A. Class decomposition** (preferred when class is also too large): Split the class so each piece needs fewer deps. Already handled by Groups 3 and 4 for GitRepository and ItemCreateHandler.

**B. Accept for small constructors**: For constructors with 5 args that can't be meaningfully split further, document as acceptable DI injection point. The convention's purpose is to reduce cognitive complexity — a constructor with named, typed dependencies has minimal cognitive load.

### Files requiring constructor decomposition

**Reviewed**: Concrete strategies added per Phase 3 review (no placeholders).

| File | Current Args | Strategy | Target Args |
|------|-------------|----------|-------------|
| `UpdateHandler::__construct` | 11 | After GitRepository split, deps like `Logger`, `TranslationService`, and `VersionCheckService` remain direct. Group related deps: `gitRepository`, `gitBranchService`, `gitSetupService` replace single `gitRepository`. Net: 11 → ~8. Further: extract `UpdateDownloader` service to hold `fileSystem`, `updateFileService`, `versionCheckService`. Target: ≤ 6 | 6 |
| `ItemCreateHandler::handle` | 11 | → 1 via `ItemCreateInput` DTO | 1 |
| `BranchRenameHandler::__construct` | 8 | After GitRepository split, `gitBranchService` replaces some gitRepository usage. Extract `BranchRenameContext` DTO for `handle/performRename/handlePostRenameActions` method args | 5-6 |
| `SubmitHandler::__construct` | 8 | After GitRepository split, 3 git-related deps become 2 (`gitRepository` + `gitSetupService`). Net: 7. Borderline acceptable for DI. | 7 |
| `ItemTakeoverHandler::__construct` | 7 | After GitRepository split, git deps reduce by 1. Target: 6 | 6 |

### Files with borderline constructors (5-6 args)

These will be assessed during implementation. If the class is otherwise compliant and the dependencies are genuinely needed, a constructor with 5 dependencies for DI is pragmatically acceptable. The metric is most meaningful for non-constructor methods.

| File | Args | Assessment |
|------|------|------------|
| `BranchCleanHandler::__construct` | 5 | Borderline, assess during impl |
| `CommitHandler::__construct` | 5 | Borderline, assess during impl |
| `ItemStartHandler::__construct` | 6 | Borderline, assess during impl |
| `ReleaseHandler::__construct` | 6 | Borderline, assess during impl |
| `VersionCheckService::__construct` | 6 | Borderline, assess during impl |
| `GitLabProvider::__construct` | 5 | Borderline, assess during impl |

### DTO/Response constructor violations — documented deviation

DTOs and Response objects are pure data containers using PHP 8 promoted properties. Their constructors naturally have many parameters since they hold all response fields. Applying the 4-arg limit literally would force sub-DTOs that add complexity without reducing cognitive load.

**Action**: Add an explicit exemption to `CONVENTIONS.md` under Method Arguments:
> *"DTO, Response, and Value Object constructors using promoted readonly properties are exempt from the 4-argument limit, as they serve as pure data containers with no behavioral complexity."*

**Reviewed**: Per Phase 3 review, this must be a formal CONVENTIONS amendment, not a silent exception.

Affected files (will become compliant after CONVENTIONS update):
- `WorkItem::__construct` (11 args) — core domain DTO
- `PullRequestComment::__construct` (5 args) — data DTO
- `PullRequestData::__construct` (5 args) — data DTO
- `ConfigShowResponse::__construct` (7 args) — response DTO
- `ConfigValidateResponse::__construct` (6 args) — response DTO
- `ItemCreateResponse::__construct` (5 args) — response DTO
- `ItemListResponse::__construct` (5 args) — response DTO
- `PrCommentsResponse::__construct` (6 args) — response DTO

### Non-constructor method argument fixes

**Reviewed**: Per Phase 3 review, avoid over-engineering DTOs for borderline 5-arg methods. Prefer method splitting or parameter elimination over wrapping in a bag.

| File | Method | Current | Strategy |
|------|--------|---------|----------|
| `BranchRenameHandler::handle` | 5 args | Create `BranchRenameContext` DTO (shared across handle/performRename/handlePostRenameActions) — justified because same 5 args are threaded through 3 methods |
| `BranchRenameHandler::performRename` | 5 args | Uses `BranchRenameContext` (same DTO) |
| `BranchRenameHandler::handlePostRenameActions` | 5 args | Uses `BranchRenameContext` (same DTO) |
| `CommitHandler::handle` | 5 args | Check if args can be derived from fewer inputs; if not, accept as borderline |
| `ReleaseHandler::handle` | 5 args | Check if args can be derived from fewer inputs; if not, accept as borderline |
| `SubmitHandler::createPullRequest` | 5 args | Check if args overlap with existing DTOs; if not, accept as borderline |
| `DescriptionFormatter::flushCurrentListItem` | 5 args | Extract `DescriptionParserState` state object (shared mutable state being passed around) |
| `DescriptionFormatter::appendNonCheckboxLine` | 6 args | Uses `DescriptionParserState` (same object) |
| `UpdateFileService::replaceBinary` | 5 args | Accept as borderline (internal method, 1 over limit) |

---

## Group 6: Provider Class Size

### 6.1 `src/Service/GithubProvider.php` — 415 code lines

**Violation**: Class size 415 (max 400)
**Strategy**: Review for method extraction. The class implements `GitProviderInterface` and has many API methods. If any methods are utility/helper in nature, extract to a shared helper or separate class. Target: reduce to ≤ 400 lines.
**Risk**: Low

### 6.2 `src/Service/GitLabProvider.php` — 405 code lines, constructor 5 args

**Violation**: Class size 405 (max 400), constructor 5 args
**Strategy**: Similar to GithubProvider — review for method extraction. Minor trimming should suffice given it's only 5 lines over.
**Risk**: Low

---

## Implementation Sequence

| Step | Group | Items | Risk | Tests Affected |
|------|-------|-------|------|----------------|
| 1 | 1.1 | Remove `final` from 5 files | Very low | None (or minimal mock updates) |
| 2 | 1.2 | Fix nesting in FileSystem, UpdateHandler, PageViewConfig | Low | May need test updates for new protected methods |
| 3 | 2.1 | Extract CommandMap from HelpService | Very low | HelpService tests |
| 4 | 2.2-2.7 | Fix method sizes (Init, Deploy, JiraAdf, MigrationExecutor, MigrationRegistry, Submit, Update) | Low | Tests for each file |
| 5 | 3.1-3.4 | Split GitRepository → GitRepository + GitBranchService + GitSetupService | Medium | GitRepository tests split across 3 test classes |
| 6 | 4.1-4.2 | Create ItemCreateInput + StandardFieldContext DTOs | Low | New DTO test files |
| 7 | 4.3 | Extract DurationParser | Very low | New test file |
| 8 | 4.4-4.6 | Extract IssueFieldResolver + ItemCreateInputCollector, slim Handler | Medium | Major test restructuring |
| 9 | 5 | Fix remaining method argument violations (non-constructor) | Low-Medium | Various test files |
| 10 | 6 | Trim GithubProvider and GitLabProvider | Low | Provider tests |
| 11 | — | Final audit re-run (Phase 5) | — | Full suite |

---

## Risk Assessment

**Highest risk items** (most lines changed, most tests affected):
1. **Step 5: GitRepository split** — 81 methods across 875 lines, used by virtually every handler. All handler tests that mock GitRepository will need updating.
2. **Step 8: ItemCreateHandler decomposition** — 32 methods across 661 lines, complex field resolution logic.

**Mitigation**: After each step, run full PHPUnit + PHPStan + PHP-CS-Fixer. Commit after each successful step. Each commit enables `git revert` if a later step breaks.

---

## Phase 3 Review Notes

**Reviewed**: 2026-03-11
**Verdict**: Approved with changes (all changes incorporated above)

### Critical fixes applied
1. ~~BranchAction `final` removal~~ — Removed from plan. `BranchAction` is a value-object constants holder; `final` is correct per CONVENTIONS.
2. `resolveExtrasAndMergeIntoFields(11 args)` — Added as item 4.6 in the decomposition plan.
3. All placeholder constructor strategies replaced with concrete decomposition plans.
4. `GitSetupService` constructor fixed: `$io` stays as method parameter, not constructor dependency.
5. DTO constructor exemption formalized: will amend CONVENTIONS.md with explicit exemption rather than silent exception.
6. Post-decomposition method signatures specified for GitSetupService (3.4).

### Accepted minor items
- CommandMap will use a class (not config file) for IDE discoverability and type safety.
- NPath Complexity: not directly measurable with current tooling; CC serves as proxy.
- CC/CRAP at-threshold methods: ItemCreateHandler and GitRepository methods will improve as side effect of decomposition. Remaining threshold methods (UpdateHandler::displayChangelog, DescriptionFormatter::processOneSectionToTitleAndContent, PageViewConfig::renderContent) are monitored but not forced below threshold since they comply today.
