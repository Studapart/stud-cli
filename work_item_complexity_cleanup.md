# Story: Reduce complexity violations to meet CONVENTIONS CC and CRAP limits

**Issue Type:** Story  
**Title:** Reduce complexity violations to meet CONVENTIONS CC and CRAP limits  

---

## User Story

**As a** maintainer or developer  
**I want** all methods in `src/` to comply with CONVENTIONS.md complexity rules (CC ≤ 10, CRAP ≤ 10 per method)  
**So that** the codebase stays maintainable, refactoring stays safe, and we meet the quality gates we have defined  

---

## Description & Implementation Logic

CONVENTIONS.md (and ADR-011) require **Cyclomatic Complexity (CC) ≤ 10** and **CRAP Index ≤ 10** per method. A recent audit found **23 methods in `src/`** that exceed one or both limits. This story is a **targeted cleanup**: refactor each violating method so it meets the thresholds while **preserving behaviour** and keeping or improving test coverage. No new features; no change to public API or user-visible behaviour.

### Audit result (initial list)

The following methods were reported as exceeding CC and/or CRAP limits. The implementing agent may re-run or deepen the analysis (e.g. PHPStan, or the same tool used for the audit) to obtain a definitive list before refactoring; any method that no longer appears in a fresh run can be skipped. New violations introduced elsewhere must not be left in place.

| File | Method (line) | CC | CRAP |
|------|----------------|-----|------|
| HelpService.php | formatCommandHelpFromTranslation (L185) | 47 | 47 |
| SubmitHandler.php | handle (L35) | 32 | 32 |
| ItemCreateHandler.php | promptForExtraRequiredFields (L308) | 29 | 29 |
| ItemCreateHandler.php | handle (L24) | 27 | 27 |
| CommitHandler.php | handle (L25) | 20 | 20 |
| GitRepository.php | ensureGitTokenConfigured (L1182) | 21 | 21 |
| UpdateHandler.php | isTestEnvironment (L558) | 18 | 18 |
| DescriptionFormatter.php | parseSections (L50) | 17 | 17 |
| DescriptionFormatter.php | formatContentForDisplay (L158) | 17 | 17 |
| GitRepository.php | ensureBaseBranchConfigured (L943) | 17 | 17 |
| ItemCreateHandler.php | resolveStandardFieldsAndExtraRequired (L418) | 16 | 16 |
| ItemCreateHandler.php | parseOriginalEstimateToSeconds (L602) | 16 | 16 |
| ItemStartHandler.php | handleTransition (L80) | 16 | 16 |
| PrCommentsResponder.php | renderSingleComment (L106) | 16 | 16 |
| HelpService.php | getCommandHelp (L80) | 15 | 15 |
| InitHandler.php | resolveGitTokenForInit (L247) | 14 | 14 |
| MarkdownToAdfConverter.php | convertBlock (L75) | 14 | 14 |
| SubmitHandler.php | validateAndProcessLabels (L265) | 13 | 13 |
| ItemTransitionHandler.php | handle (L24) | 11 | 11 |
| UpdateHandler.php | handle (L37) | 11 | 11 |
| PageViewConfig.php | renderSection (L52) | 11 | 11 |
| ChangelogParser.php | parse (L19) | 11 | 11 |
| GitLabProvider.php | getPullRequestReviewComments (L429) | 11 | 11 |

### Approach (per method)

- **Refactor** to reduce CC: extract smaller methods, use early returns, replace nested conditionals with guard clauses or lookup tables where appropriate. See CONVENTIONS.md "Example of refactoring high complexity" and ADR-011.
- **CRAP** is reduced by (1) lowering CC and/or (2) increasing test coverage. Prefer reducing CC; add or extend tests where needed to keep CRAP ≤ 10 after refactoring.
- **Visibility:** New helper methods should follow CONVENTIONS (e.g. `protected` for testable logic, `private` for trivial helpers). Do not break existing tests or public API.
- **One method at a time:** Refactor, run tests, confirm metrics (re-run audit or PHPStan), then move to the next. Order is left to the implementer (e.g. by file, or by worst violation first).

### Optional: Confirm the list

- Re-run the same audit (or PHPStan complexity rules) on current `main`/`develop` to get an up-to-date list. If the list differs from the table above, use the new list as the scope. Document the tool and command used so the check can be repeated (e.g. in README or CONVENTIONS).

---

## Definition of done per method

For **each** violating method, before considering it done:

- [ ] **Refactored:** CC ≤ 10 and CRAP ≤ 10 for that method (and any new helpers introduced).
- [ ] **Behaviour unchanged:** Existing tests for that code path still pass; no change to public API or user-visible behaviour.
- [ ] **Tests:** If CRAP was reduced partly by adding tests, those tests are committed; existing tests are not removed unless redundant.
- [ ] **Conventions:** New methods comply with CONVENTIONS (visibility, method size, argument count, etc.).

---

## Assumptions and Constraints

- Refactoring only; no new features or change to observable behaviour.
- CONVENTIONS.md and ADR-011 are the source of truth for CC and CRAP thresholds and refactoring guidance.
- The audit list may be updated after a re-run; the scope is "all methods in `src/` that currently exceed CC or CRAP limits."
- PHPStan and existing PHPUnit tests are used to verify no regressions; the same tooling used for the audit (or PHPStan) should confirm compliance after refactoring.

---

## Acceptance Criteria

- [ ] A definitive list of methods in `src/` that exceed CC > 10 or CRAP > 10 is established (from a re-run of the audit or PHPStan, or the table above if confirmed up to date).
- [ ] Every method on that list has been refactored so that it (and any new helpers) meet CC ≤ 10 and CRAP ≤ 10.
- [ ] All existing tests pass; no intentional change to public API or user-visible behaviour.
- [ ] For each refactored method, the "Definition of done per method" checklist has been satisfied.
- [ ] Optionally: the command or tool used to measure CC/CRAP is documented (e.g. in CONVENTIONS or README) so the check can be repeated in the future.
