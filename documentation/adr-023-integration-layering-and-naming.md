# ADR-023: Integration layering and naming (ports, adapters, config providers)

* **Status:** Accepted
* **Date:** 2026-06-26
* **Authors:** stud-cli maintainers
* **Technical Context:** SCI-143 multi-provider epic, SCI-159–162 work-item port migration, ADR-021 hexagonal outbound ports, ADR-022 scope mapping

## 1. Context and Problem Statement

stud-cli integrates with external systems (local git, GitHub/GitLab, Jira/Linear, Confluence). Naming overloads **Provider** for config tokens, outbound port interfaces, and vendor implementations. The work-item port mixes repository-shaped operations with provider-native query dialects (JQL vs Linear search terms). Contributors reasonably ask whether classes are repositories, adapters, or facades.

**Goal:** A shared glossary and layering model that is accurate enough for collaboration, pragmatic enough to finish the epic, and explicit about what we do **not** build.

stud-cli is an **integration orchestrator**, not an application that owns domain aggregates in a local database.

## 2. Decision Drivers & Constraints

* **ADR-005:** Handlers return Response DTOs; no I/O in handlers.
* **ADR-018:** User-facing text via `MessageRef` at presentation boundaries.
* **ADR-019:** Config provider values (`jira`, `linear`, `github`, `gitlab`) stay backed enums — the word *provider* remains valid in **config** vocabulary.
* **ADR-021:** Command guard complements hexagonal **outbound ports**; marker interfaces stay during migration.
* **ADR-022:** Issue-tracker scope is Jira project / Linear team — injected via config and factory, not per-call opaque parameters on `search()`.
* **YAGNI:** No extra repository layer between handlers and ports; no micro-adapters per issue sub-resource unless a real second use case appears.
* **Pragmatism:** Renames are incremental; user-facing command names (`items:*`, `confluence:*`) stay stable.

## 3. Integration domains

Four outbound integration domains plus local infrastructure:

| Domain | Representative commands | Role |
|--------|-------------------------|------|
| **Local VCS** | `commit`, `push`, `switch`, `branches:*` | Git subprocess + `.git/stud.config` on disk |
| **Remote Git hosting** | `submit`, `pr:*` | PR/MR, labels, review comments on GitHub/GitLab |
| **Issue tracking (PM)** | `items:*`, `projects:*`, `filters:*` | Jira issues / Linear issues (stud `WorkItem` DTO) |
| **Wiki** | `confluence:*` | Confluence pages (separate from issue tracking) |

```mermaid
flowchart TB
  subgraph handlers [Handlers — use cases]
    H[ItemStartHandler, SubmitHandler, ConfluenceShowHandler, ...]
  end

  subgraph ports [Outbound ports — stud vocabulary]
    IT[IssueTrackerPort]
    GH[GitHostingPort]
    WK[WikiPort]
  end

  subgraph infra [Local infrastructure — not polymorphic]
    GR[GitRepository + GitBranchService]
  end

  subgraph adapters [Adapters — per vendor]
    JA[JiraIssueTrackerAdapter]
    LA[LinearIssueTrackerAdapter]
    GA[GithubGitHostingAdapter]
    GLA[GitLabGitHostingAdapter]
    CA[ConfluenceWikiAdapter]
  end

  subgraph clients [HTTP / GraphQL clients]
    JC[JiraApiClient]
    LC[LinearApiClient]
    GC[Github API client]
    CC[ConfluenceApiClient]
  end

  H --> IT
  H --> GH
  H --> WK
  H --> GR

  IT --> JA
  IT --> LA
  GH --> GA
  GH --> GLA
  WK --> CA

  JA --> JC
  LA --> LC
  GA --> GC
  GLA --> GC
  CA --> CC
```

**Note:** `GitRepository` means *git repository on disk* (see ADR-015). It is **not** a DDD repository and not renamed in this ADR.

## 4. Glossary

| Term | Meaning | Use on |
|------|---------|--------|
| **Provider** (config) | Which vendor integration is enabled in YAML / init | `enum WorkItemProvider`, `GIT_PROVIDERS`, `WORK_ITEM_PROVIDERS` |
| **Port** | Outbound interface handlers depend on; stud-facing vocabulary | `IssueTrackerPort`, `GitHostingPort`, `WikiPort` |
| **Adapter** | Port implementation for one vendor; anti-corruption layer | `JiraIssueTrackerAdapter`, `GithubGitHostingAdapter` |
| **Client** | HTTP/GraphQL + JSON mapping; no handler-facing API | `JiraApiClient`, `ConfluenceApiClient`, `LinearApiClient`; inline HTTP in git hosting adapters |

**Repository-shaped** describes operation style (`getByKey`, `search`, `create`, `update`). It does not require the class name *Repository* for remote APIs.

**Gateway** describes remote Git hosting operations (PRs, comments) — port concern, not local persistence.

## 5. Port catalogue and class mapping

Current class names (post SCI-162 / SCI-163 follow-up):

### Issue tracking (PM)

| Class | Role |
|-------|------|
| `IssueTrackerPort` | Issue CRUD, search, transitions, attachments, project/team list, discovery metadata (`listProjectStateChanges`, `listLabelGroups`, …) |
| `JiraIssueTrackerAdapter` | JQL and Jira REST delegation |
| `LinearIssueTrackerAdapter` | Linear discovery metadata today; full issue parity in SCI-164+ |
| `IssueTrackerFactory` / `IssueTrackerPortSupplier` | Config → adapter; keeps HTTP clients out of handlers |
| `JiraApiClient` (+ `JiraAttachmentService`, mappers) | Low-level Jira REST |
| `LinearApiClient` | Linear GraphQL for workflow states and label groups |

`WorkItem` DTO and `items:*` commands stay for user-facing stability.

### Remote Git hosting

| Class | Role |
|-------|------|
| `GitHostingPort` | PR/MR, comments, labels, assign |
| `GithubGitHostingAdapter` | GitHub REST/GraphQL |
| `GitLabGitHostingAdapter` | GitLab REST |

### Wiki

| Class | Role |
|-------|------|
| `WikiPort` | `getPage`, push/update page, URL resolution |
| `ConfluenceWikiAdapter` | Delegates to `ConfluenceApiClient` |
| `ConfluenceApiClient` | Confluence REST v2 |

### Local VCS (infrastructure)

| Class | Role |
|-------|------|
| `GitRepository` | Low-level git commands, project config I/O |
| `GitBranchService` | Branch resolution, status, switch helpers |
| `GitSetupService` | Interactive git setup prompts |

Handlers inject these directly — no polymorphic port.

### Config (keep *Provider*)

| Class / enum | Role |
|--------------|------|
| `GitProvider`, `WorkItemProvider` | Stored vendor tokens |
| `GlobalConfigProviderResolver` | Normalize `*_PROVIDERS` lists |

## 6. Decisions (near-term implementation)

### 6.1 Drop `$context` on `search()`

`IssueTrackerPort::search(string $query)` — no per-call opaque context. Team/project scope comes from project config and factory-injected adapter state (ADR-022).

### 6.2 Exceptions: external vs internal

| Kind | Layer | Pattern |
|------|-------|---------|
| **External** (Jira/Linear/Git/Confluence HTTP) | Client / adapter | `ApiException` with English summary + `technicalDetails` — **not** translation keys |
| **Internal** (stud-cli validation, config) | Handler / domain | `MessageRef` keys; translated at responder (ADR-018) |
| **Caught external in handlers** | Handler → Response | `MessageRef::key('…', ['error' => $e->getMessage()])` — not raw `Response::error($e->getMessage())` |

### 6.3 Jira JQL literals

JQL fragments are **protocol vocabulary**, not user-facing copy. Use `JiraJqlFragments`, `JiraStatusCategory`, and `JiraAssignedActiveJqlBuilder` for shared list/dashboard JQL.

## 7. What we deliberately do not do

* **No Handler → Repository → Provider → Client chain.** Handlers → Port → Adapter → Client.
* **No attachment / transition / metadata micro-adapters** split from the issue tracker port.
* **No merge of Confluence into the issue tracker port.**
* **No mass rename of `GitRepository`.**
* **No DTO/command rename** (`WorkItem`, `items:*`) unless explicitly scheduled.
* **No translation keys inside `ApiException`.**

## 8. Phased rename and migration plan

| Phase | Status | Actions |
|-------|--------|---------|
| **A — Port wiring** | Done (SCI-162) | Handlers on issue tracker port; `search()` without `$context`; JQL in Jira adapter; `MessageRef` in touched handlers |
| **B — Glossary docs** | Done | ADR-023; cross-link from ADR-021 |
| **C — Adapter renames** | Done | `JiraIssueTrackerAdapter`, `LinearIssueTrackerAdapter`, git/wiki adapters |
| **D — Port renames** | Done | `IssueTrackerPort`, factory/resolver/supplier |
| **E — Git hosting + wiki ports** | Done | `GitHostingPort`, `WikiPort`, `ConfluenceWikiAdapter` |
| **F — Client renames** | Done (SCI-163 follow-up) | `JiraApiClient`, `ConfluenceApiClient`, `LinearApiClient` |
| **Enforcement** | Done (SCI-163) | Architecture test: handlers must not import integration clients |

## 9. Open questions

| Question | Options | Notes |
|----------|---------|-------|
| Port name | `IssueTrackerPort` vs `PmPort` | **IssueTrackerPort** is authoritative |
| Git hosting port name | `GitHostingPort` vs `PullRequestPort` | **GitHostingPort** |
| DTO rename | Keep `WorkItem` vs `TrackedIssue` | Defer |
| `GlobalConfigProviderResolver` rename | `IntegrationConfigResolver` | Cosmetic; optional |
| Split discovery from tracker port | `IssueTrackerDiscoveryPort` | Only if port remains too wide after Linear parity |
| `UpdateHandler` + releases | Extend `GitHostingPort` vs dedicated release client | Still uses `GithubGitHostingAdapter` for self-update |

## 10. Consequences

| Aspect | Result |
|--------|--------|
| Onboarding | (+) Shared vocabulary; less adapter vs repository debate |
| SCI-143 | (+) Clear rules for handlers, exceptions, `search` signature |
| Renames | (−) Phased churn in tests, castor helpers, docs |
| Strict DDD | (Neutral) stud-cli stays integration-first; aggregates are external |

## 11. Cross-references

* [ADR-005](adr-005-responder-pattern-architecture.md) — Handler → Response → Responder
* [ADR-015](adr-015-git-repository-decomposition.md) — `GitRepository` scope
* [ADR-018](adr-018-presentation-owned-translation.md) — `MessageRef` at presentation
* [ADR-019](adr-019-closed-prompt-choices-use-backed-enums.md) — config provider enums
* [ADR-021](adr-021-command-readiness-guard.md) — outbound ports + guard
* [ADR-022](adr-022-jira-linear-work-item-scope-mapping.md) — team/project scope
