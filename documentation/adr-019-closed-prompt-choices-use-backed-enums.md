# [ADR-019] Closed prompt choices use backed enums

* **Status:** `Accepted`
* **Date:** 2026-06-17
* **Authors:** Engineering Team
* **Deciders:** Head of Engineering
* **Technical Context:** PHP 8.2+, stud-cli config wizards (SCI-150), ADR-010 i18n

## 1. Context and Problem Statement

**The Pain Point:** Interactive wizards used raw strings for finite menus (`'0 GitHub'`, `'2 Both'`) and `match` on display labels. That duplicated translation text in PHP, made agent/interactive mapping fragile, and scattered provider token literals (`github`, `jira`) across handlers.

**The Goal:** Use a single canonical representation for closed prompt choices and stored provider values, with labels rendered from translations at the presentation boundary.

## 2. Decision Drivers & Constraints

* **CONVENTIONS:** User-facing text via translation keys; domain literals as enums/constants when stable (Constants, Enums, and Literals section).
* **ADR-010 / ADR-018:** Labels rendered through `MessageRenderer` / `MessageRef`; handlers do not embed locale-specific menu text.
* **ADR-016 precedent:** Decompose oversized handlers; extract prompt collectors.
* **YAGNI:** Free-text prompts (URLs, tokens, project keys) stay as validated string input — not every prompt becomes an enum.

## 3. Considered Options

* **Option 1:** Keep string literals and `match` in handlers
  * Pros: No new types
  * Cons: Duplication, i18n drift, hard to reuse in SCI-152 validation

* **Option 2:** Backed enums for stored values + menu enums for numbered choices (Chosen)
  * Pros: Type-safe provider lists, one mapping path, translation keys on menu enum
  * Cons: More types; legacy handlers migrated when touched

* **Option 3:** Single mega-enum for all providers (Git + PM)
  * Pros: One type
  * Cons: Mixes unrelated domains; awkward YAML list shapes

* **Option 4:** Enum for `INPUT_TO_YAML` field maps
  * Pros: Strong typing per key
  * Cons: Verbose for schema maps; `ProjectStudConfigFieldMap` pattern is already established

## 4. Decision Outcome

**Chosen Option:** `Option 2` for provider/menu choices; keep **class constant maps** for agent field name → YAML key mapping.

### Provider value enums (stored in config)

| Enum | Values | Use |
|------|--------|-----|
| `GitProvider` | `github`, `gitlab` | `GIT_PROVIDERS` YAML lists |
| `WorkItemProvider` | `jira`, `linear` | `WORK_ITEM_PROVIDERS` YAML lists |

### Menu enums (interactive numbered choices)

| Enum | Cases | Maps to |
|------|-------|---------|
| `GlobalGitProviderMenu` | `GithubOnly`, `GitlabOnly`, `Both` | `list<GitProvider>` |
| `GlobalWorkItemProviderMenu` | `JiraOnly`, `LinearOnly`, `Both` | `list<WorkItemProvider>` |

Each menu case exposes:

- `choiceMessageKey()` → translation key (e.g. `config.init.git_provider.choice_github`)
- `toProviderValues()` → YAML list values
- `fromProviderValues()` / `fromRenderedChoice()` for defaults and interactive resolution

### Field maps stay constants

`GlobalStudConfigFieldMap::INPUT_TO_YAML` and `ProjectStudConfigFieldMap::INPUT_TO_YAML` remain `final` classes with `public const` arrays — they describe agent JSON schema, not behavioral closed sets.

### Handler decomposition (SCI-150)

| Class | Role |
|-------|------|
| `InitHandler` | Orchestration: load, migrate, save, shell completion |
| `InitPromptCollector` | Interactive + agent global config prompts |
| `InitPromptInputHelper` | Reusable visible/hidden prompt guards |
| `GlobalConfigProviderResolver` | Normalize/infer provider lists |

Remove unused constructor parameters (e.g. discarded `$translator`); wire `MessageRenderer` into prompt collectors only.

## 5. Consequences

| Aspect | Result |
| --- | --- |
| **i18n** | (+) Menu labels live in translation files only |
| **Reuse** | (+) SCI-151/152 share `GitProvider`, `WorkItemProvider`, resolver |
| **Size** | (+) `InitHandler` back under 400 LOC |
| **Migration** | (-) Legacy handlers (`ItemStartHandler`, etc.) updated when touched, not big-bang |
| **Tests** | (+) Enum unit tests; prompt guard tests target `InitPromptInputHelper` |

## 6. Rollout

* **New code:** MUST use backed enums for closed choices and provider tokens.
* **Existing code:** Refactor when modifying a handler; no repo-wide sweep required in SCI-143.
* **Agent mode:** Accepts provider string arrays; normalization goes through `GlobalConfigProviderResolver` + value enums.

## 7. Implementation Plan

* [x] Add `GitProvider`, `WorkItemProvider`, menu enums (SCI-150)
* [x] Extract `InitPromptCollector`, `InitPromptInputHelper`, `GlobalConfigProviderResolver`
* [x] Add per-choice translation keys under `config.init.*_provider.choice_*`
* [ ] Apply same pattern to `ConfigProjectInitPromptCollector` when SCI-151 touches git/PM pickers
* [ ] Reuse provider enums in `ConfigValidateHandler` (SCI-152)
