# [ADR-020] Global init wizard: Director + Strategy decomposition

* **Status:** `Accepted`
* **Date:** 2026-06-17
* **Authors:** Engineering Team
* **Deciders:** Head of Engineering
* **Technical Context:** PHP 8.2+, `stud config:init` (SCI-150), ADR-016/019 precedents, SCI-151 project-init ahead

## 1. Context and Problem Statement

**The Pain Point:** `InitPromptCollector::buildGlobalConfig` repeated the same conditional prompt pattern per field (`active ? agent|interactive prompt : keep stored`). Logic was simple; encoding was noisy. One class mixed orchestration (language, menus), Jira credentials, Linear API key, and Git token flows — an SRP drift risk as SCI-151+ add more provider wizards.

**The Goal:** Keep `buildGlobalConfig` readable as a workflow recipe, dedupe prompt mechanics, and isolate each provider bundle behind a focused collector without inventing a generic framework (attributes, field registries, generators).

## 2. Decision Drivers & Constraints

* **ADR-016:** Decompose oversized handlers; extract prompt collectors.
* **ADR-019:** Menu/provider enums and `GlobalConfigProviderResolver` stay; this ADR covers free-text credential prompts only.
* **SRP:** Director orchestrates order; strategies own one provider slice; helper owns how to ask; resolver owns when a provider is active.
* **YAGNI:** No `ProviderPromptCollectorInterface` until a second orchestrator needs it (SCI-151 uses its own `ConfigProjectInitPromptCollector` with shared helper only).
* **KISS:** Concrete strategy classes under `App\Handler\GlobalInit\`; no abstract base, no reflection registry.

## 3. Considered Options

* **Option 1:** Inline ternaries in `InitPromptCollector` (status quo)
  * Pros: No new types
  * Cons: Poor scanability; copy-paste drift

* **Option 2:** `resolveWhenActive()` helper only
  * Pros: DRY ternaries
  * Cons: `buildGlobalConfig` still large; provider sections mixed

* **Option 3:** Director + Strategy + shared helper (Chosen)
  * Pros: Clear boundaries; testable slices; reusable helper for SCI-151
  * Cons: More files; must guard against strategy/orchestrator bleed

* **Option 4:** GoF Builder for config array
  * Pros: Familiar name
  * Cons: Misleading — wizard has I/O, skips, side effects; not representation construction

* **Option 5:** PHP attributes + generic field engine
  * Pros: Declarative
  * Cons: Heterogeneous fields (bool transition, `GitTokenPromptResolver`); reflection complexity; god engine risk

* **Option 6:** Template Method base class
  * Pros: Shared skeleton
  * Cons: Inheritance coupling; global vs project-init diverge

## 4. Decision Outcome

**Chosen Option:** `Option 3` — **Director + Strategy** with **`InitPromptInputHelper::resolveWhenActive()`**.

### Roles

| Pattern role | Class | Responsibility |
|--------------|-------|----------------|
| Facade | `InitHandler` | Load, migrate, save, shell completion |
| Director | `InitPromptCollector` | Language, provider menus, merge strategy slices |
| Strategy | `GlobalInit\JiraCredentialsCollector` | Jira section + URL/email/token; transition via `collectTransitionEnabled()` after git tokens |
| Strategy | `GlobalInit\LinearApiKeyCollector` | Linear section + `LINEAR_API_KEY` |
| Strategy | `GlobalInit\GitProviderTokensCollector` | Git section + GitHub/GitLab tokens via `GitTokenPromptResolver` |
| Helper | `InitPromptInputHelper` | `resolveWhenActive`, visible/hidden/agent guards |
| Domain | `GlobalConfigProviderResolver` | Active provider? normalize lists |
| Context DTO | `GlobalInit\GlobalInitPromptContext` | Shared inputs passed to strategies |

### `resolveWhenActive` contract

When `$active` is false → `nonEmptyStoredString` on existing value. When true → agent path (`promptRequiredAgentString`) or interactive (`promptRequiredVisible` / `promptRequiredHiddenToken`). Does **not** decide whether a provider is active.

### Namespace scope

`App\Handler\GlobalInit\*` is **global init only**. SCI-151 `ConfigProjectInitPromptCollector` reuses `InitPromptInputHelper`, not these strategy classes.

### Explicit non-goals

* No `AbstractProviderPromptCollector`
* No attribute-driven field registry
* No `ProviderPromptCollectorInterface` until a second Director exists

## 5. Consequences

| Aspect | Result |
| --- | --- |
| **Readability** | (+) `buildGlobalConfig` reads as ordered merge |
| **SRP** | (+) One class per provider bundle |
| **Reuse** | (+) Helper shared with project-init; strategies stay global-scoped |
| **Files** | (-) +4 types (`Context` + 3 collectors) |
| **SCI-151** | (+) Pattern documented; project collector mirrors Director + helper |

## 6. Rollout

* **Global init:** MUST use `GlobalInit\*` collectors for provider credential slices.
* **Project init (SCI-151):** New `ConfigProjectInitPromptCollector` methods; reuse `InitPromptInputHelper` only.
* **New providers:** Add a new concrete collector under `GlobalInit\`; wire in `InitPromptCollector` constructor; do not extend a shared base.

## 7. Implementation Plan

* [x] Add `GlobalInitPromptContext` and three provider collectors
* [x] Add `InitPromptInputHelper::resolveWhenActive()`
* [x] Slim `InitPromptCollector` to Director role
* [ ] Apply helper to `ConfigProjectInitPromptCollector` (SCI-151)
* [ ] Introduce `ProviderPromptCollectorInterface` only if a second Director needs polymorphic strategies
