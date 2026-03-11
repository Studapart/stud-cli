# [ADR-016] ItemCreateHandler Decomposition and DTO Introduction

* **Status:** `Accepted`
* **Date:** 2026-03-11
* **Authors:** Engineering Team
* **Deciders:** Head of Engineering
* **Technical Context:** PHP 8.2+, Castor Framework, stud-cli quality audit (SCI-71)

## 1. Context and Problem Statement

**The Pain Point:** `ItemCreateHandler` had grown to 661 code lines and concentrated multiple responsibilities:
- **Issue field resolution** (mapping Jira createmeta to create-payload fields, handling standard vs. custom required fields)
- **Duration parsing** (converting human-readable durations like `1d`, `2h`, `30m` to seconds)
- **Orchestration** (project resolution, interactive prompts, error handling, API calls)

The `handle()` method accepted 11 parameters (CLI options passed individually), causing method signatures throughout the call chain to balloon. With `php-cs-fixer`'s `ensure_fully_multiline` rule, each parameter occupied its own line, consuming line budget on signatures alone and pushing methods well past the 40-line limit.

**The Goal:** Decompose the handler into focused units and introduce DTOs to reduce parameter counts, bringing all methods under the 40-line and 4-argument limits.

## 2. Decision Drivers & Constraints

* **Class Size Metric:** CONVENTIONS requires ≤ 400 code lines per class
* **Method Size Metric:** CONVENTIONS requires ≤ 40 code lines per method
* **Method Arguments:** CONVENTIONS requires ≤ 4 arguments per method (DTO constructors exempt)
* **Code Style:** `php-cs-fixer` enforces `ensure_fully_multiline`, so each parameter is a separate line
* **Testability:** The handler has 30+ test cases; refactoring must preserve all behavior
* **YAGNI:** Don't introduce abstractions beyond what the current violations require

## 3. Considered Options

* **Option 1:** Only extract method bodies into smaller helpers
  * Pros: Minimal structural change
  * Cons: Doesn't fix argument count; signatures still eat line budget

* **Option 2:** Create `ItemCreateInput` DTO + extract `IssueFieldResolver` and `DurationParser` (Chosen)
  * Pros: Fixes both size and argument violations; clean SRP split
  * Cons: New files, test updates, castor.php update

* **Option 3:** Full CQRS-style separation (command object + handler + response pipeline)
  * Pros: Very clean separation
  * Cons: Over-engineered for a single handler, violates YAGNI

## 4. Decision Outcome

**Chosen Option:** `Option 2 — DTO + service extraction`

**Justification:**

### New classes

| Class | Purpose | Type |
|-------|---------|------|
| `ItemCreateInput` | Bundles 9 CLI options into one immutable DTO | `final` DTO |
| `IssueCreationState` | Bundles resolved metadata (projectKey, issueTypeId, allFieldsMeta, requiredFieldIds, fields) passed between orchestration steps | `final` mutable state container |
| `IssueFieldResolver` | Pure field-resolution logic: type mapping, standard field filling, optional field application | Service |
| `DurationParser` | Converts human-readable durations (`1d`, `2h`, `30m`) to seconds | Service |

### Design decisions

**`ItemCreateInput` (immutable DTO):** Replaces 9 positional parameters on `handle()` with a single typed object. The constructor uses promoted `readonly` properties and is exempt from the 4-argument limit per CONVENTIONS.

**`IssueCreationState` (mutable state container):** Bundles the metadata tuple returned by `resolveTypeMetadata()` (projectKey, issueTypeId, allFieldsMeta, requiredFieldIds, fields). The `fields` property is intentionally mutable — downstream methods (`resolveExtrasAndMergeIntoFields`, `promptAndMergeExtraFields`) add entries to it as they resolve standard and prompted field values. This avoids passing 5+ metadata values as individual arguments through every method in the chain.

**`IssueFieldResolver` accepts a `$fieldValues` array** instead of 6 individual parameters for the standard-field-filling logic. A typed array shape (`@param array{projectKey: string, ...}`) provides PHPStan safety without requiring yet another DTO.

## 5. Consequences (Trade-offs)

| Aspect | Result (Positive / Negative / Neutral) |
| --- | --- |
| **Method Arguments** | *(+) `handle()`: 11 → 3 args; `resolveExtrasAndMergeIntoFields`: 10 → 4 args* |
| **Method Size** | *(+) `handle()`: 62 → 29 lines; all methods now ≤ 40* |
| **Class Size** | *(+) ItemCreateHandler: 661 → 499 lines* |
| **SRP** | *(+) Field resolution, duration parsing, and orchestration are separate concerns* |
| **Test Impact** | *(-) 30+ test call sites updated to construct `ItemCreateInput`; 3 IssueFieldResolver tests updated* |
| **File Count** | *(-) 4 new files (2 DTOs, 2 services)* |
| **Discoverability** | *(+) Field logic → IssueFieldResolver; duration parsing → DurationParser; CLI input shape → ItemCreateInput* |

## 6. Implementation Plan

* [x] Create `src/DTO/ItemCreateInput.php` — immutable CLI input DTO
* [x] Create `src/DTO/IssueCreationState.php` — mutable metadata state container
* [x] Extract `src/Service/IssueFieldResolver.php` — field resolution logic from handler
* [x] Extract `src/Service/DurationParser.php` — duration parsing logic from handler
* [x] Refactor `ItemCreateHandler::handle()` to accept `ItemCreateInput`
* [x] Refactor `resolveExtrasAndMergeIntoFields()` to accept `IssueCreationState`
* [x] Extract `promptAndMergeExtraFields()` to separate prompting from resolution
* [x] Merge `fillStandardFieldByName` + `applyStandardFieldValue` into single method using `$fieldValues` array
* [x] Update `castor.php` to construct `ItemCreateInput`
* [x] Update all 30+ test calls in `ItemCreateHandlerTest`
* [x] Add `_get_issue_field_resolver()` and `_get_duration_parser()` service locator helpers
* [x] Verify: PHPUnit, PHPStan, PHP-CS-Fixer all green
