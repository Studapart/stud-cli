# [ADR-015] GitRepository Decomposition into GitBranchService and GitSetupService

* **Status:** `Accepted`
* **Date:** 2026-03-11
* **Authors:** Engineering Team
* **Deciders:** Head of Engineering
* **Technical Context:** PHP 8.2+, Castor Framework, stud-cli quality audit (SCI-71)

## 1. Context and Problem Statement

**The Pain Point:** `GitRepository` had grown to 875 code lines (limit: 400), mixing three unrelated responsibilities:
- **Low-level git operations** (commit, push, rebase, branch CRUD, remote URL parsing)
- **Branch management logic** (resolving latest base branch, switching branches, comparing refs)
- **Interactive configuration** (prompting the user for git provider, token, base branch via SymfonyStyle)

This violated the Single Responsibility Principle: a change to the branch-resolution algorithm could accidentally break remote URL parsing; interactive setup logic forced a `SymfonyStyle` dependency into a class that otherwise dealt with `Process` objects.

**The Goal:** Split `GitRepository` into focused services that each have one reason to change, while keeping the public API surface small and the dependency graph simple.

## 2. Decision Drivers & Constraints

* **SRP (SOLID):** Each class should have one reason to change
* **Class Size Metric:** CONVENTIONS requires ≤ 400 code lines per class
* **Testability:** Interactive I/O (`SymfonyStyle`) should not leak into non-interactive services
* **Backward Compatibility:** All existing callers (handlers, `castor.php`) must keep working
* **Service Locator Pattern:** New services must integrate with the `_get_*()` helper functions (ADR-009)

## 3. Considered Options

* **Option 1:** Keep one class, extract private helpers only
  * Pros: No new files, no caller changes
  * Cons: Still violates class size limit, still mixes I/O with git operations

* **Option 2:** Split into GitRepository + GitBranchService + GitSetupService (Chosen)
  * Pros: Clear SRP boundaries, each class under 400 lines, interactive I/O isolated
  * Cons: Callers need updating, two new service locator helpers

* **Option 3:** Split into many fine-grained services (one per operation)
  * Pros: Very small classes
  * Cons: Over-engineered, too many service locator helpers, harder to navigate

## 4. Decision Outcome

**Chosen Option:** `Option 2 — Three focused services`

**Justification:**
Three services map cleanly to three distinct responsibilities:

| Service | Responsibility | Depends on |
|---------|---------------|------------|
| `GitRepository` | Low-level git commands, config I/O, remote URL parsing | `ProcessFactory`, `FileSystem` |
| `GitBranchService` | Branch resolution, switching, ref comparison | `GitRepository` |
| `GitSetupService` | Interactive prompts for provider, token, base branch | `GitRepository`, `Logger`, `TranslationService` |

`GitSetupService` receives `SymfonyStyle` as a method parameter (runtime dependency), not a constructor argument, keeping the service stateless between calls.

## 5. Consequences (Trade-offs)

| Aspect | Result (Positive / Negative / Neutral) |
| --- | --- |
| **SRP** | *(+) Each class has one reason to change* |
| **Class Size** | *(+) GitRepository: 392 lines, GitBranchService: 192, GitSetupService: 261* |
| **Testability** | *(+) Interactive I/O isolated in GitSetupService; GitRepository and GitBranchService are pure process wrappers* |
| **Caller Impact** | *(-) 10+ handler/test files updated to inject the new services* |
| **Service Count** | *(Neutral) Two new service locator helpers, consistent with existing pattern* |
| **Discoverability** | *(+) Developers know where to look: branch logic → GitBranchService, setup prompts → GitSetupService* |

## 6. Implementation Plan

* [x] Extract `GitBranchService` with branch management methods (`resolveLatestBaseBranch`, `switchBranch`, `switchToRemoteBranch`, `deriveLocalAndRemoteRefs`, `refExists`, `pickMoreAdvancedRef`)
* [x] Extract `GitSetupService` with interactive configuration (`ensureBaseBranchConfigured`, `ensureGitProviderConfigured`, `ensureGitTokenConfigured`)
* [x] Update all handlers and `castor.php` to inject the correct service
* [x] Add `_get_git_branch_service()` and `_get_git_setup_service()` helpers
* [x] Update `TestKernel` and all affected test files
* [x] Verify: PHPUnit, PHPStan, PHP-CS-Fixer all green
