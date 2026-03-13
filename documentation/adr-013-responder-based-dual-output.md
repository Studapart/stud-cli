# [ADR-013] Responder-Based Dual Output (CLI + JSON)

* **Status:** `Accepted`
* **Date:** 2026-03-10
* **Authors:** AI Agent
* **Deciders:** Project Lead
* **Technical Context:** PHP 8.4, Castor Framework, stud-cli, ADR-005 (Responder Pattern), ADR-012 (Agent Mode)

## 1. Context and Problem Statement

* **The Pain Point:** The initial agent mode implementation (ADR-012) placed JSON serialisation in inline closures inside `castor.php` task functions via a central `_run_agent_command()` orchestrator. Each command's agent branch duplicated serialisation logic, and the closures bypassed the Responder layer entirely — violating the ADR-005 principle that all presentation lives in Responders.
* **The Goal:** Unify CLI and JSON output in a single Responder method, so adding a new output format never requires touching task functions.

## 2. Decision Drivers & Constraints

* **ADR-005 compliance:** Presentation logic belongs in Responders, not in task functions.
* **DRY:** Inline closures duplicated DTO→array conversion across 28 commands.
* **Extensibility:** Future formats (YAML, Markdown, etc.) should only need a new `OutputFormat` case and Responder logic.
* **Backward compatibility:** CLI output must remain unchanged when `--agent` is absent.
* **100 % coverage:** All new paths must be testable without I/O side-effects.

## 3. Considered Options

* **Option 1:** Keep `_run_agent_command` closures in `castor.php` (status quo from ADR-012).
  * Pros: Already working, no refactor risk.
  * Cons: Closures duplicate serialisation, bypass Responders, violate SRP.

* **Option 2:** Move JSON output into Responders with an `OutputFormat` parameter (Chosen).
  * Pros: Single presentation entry-point, DRY, aligns with ADR-005, easy to extend.
  * Cons: Every Responder gains a new branch; requires `DtoSerializer` service.

* **Option 3:** Create separate `JsonResponder` classes per command.
  * Pros: Full separation of CLI and JSON paths.
  * Cons: Doubles the number of Responder classes, high maintenance cost.

## 4. Decision Outcome

**Chosen Option:** `Option 2 – Dual output inside existing Responders`

**Justification:**
Adding an `OutputFormat` parameter to each Responder's `respond()` method keeps the ADR-005 one-Action-one-Responder mapping intact while eliminating all 28 inline closures. A shared `DtoSerializer` service removes duplication, and a lightweight `AgentCommandResponder` covers int/void commands that lack a dedicated Responder.

### New Infrastructure

| Component | Role |
|---|---|
| `OutputFormat` enum (`Cli`, `Json`) | Selects the rendering path inside a Responder |
| `AgentJsonResponse` DTO | Standard wrapper (`success`, `data`/`error`) returned by Responders in JSON mode |
| `DtoSerializer` service | Converts any DTO (including nested objects, `DateTimeInterface`, arrays) to `array<string, mixed>` |
| `AgentCommandResponder` | Generic Responder for int/void handlers that only need a message |

### Responder Signature Change

```php
// Before (ADR-005)
public function respond(SymfonyStyle $io, ItemListResponse $response): void;

// After (ADR-013)
public function respond(
    SymfonyStyle $io,
    ItemListResponse $response,
    OutputFormat $format = OutputFormat::Cli,
): ?AgentJsonResponse;
```

When `$format` is `Cli`, the method renders as before and returns `null`.
When `$format` is `Json`, it serialises the Response DTO via `DtoSerializer` and returns an `AgentJsonResponse` — no console output is emitted.

## 5. Consequences (Trade-offs)

| Aspect | Result |
|---|---|
| **ADR-005 alignment** | (+) All presentation logic now lives in Responders, for both formats. |
| **DRY** | (+) Serialisation logic is in one place (`DtoSerializer`); 28 closures removed. |
| **Extensibility** | (+) New format = new `OutputFormat` case + Responder branch; task functions untouched. |
| **Responder complexity** | (-) Each Responder gains a JSON branch (~5–10 lines). |
| **Return type change** | (Neutral) `void` → `?AgentJsonResponse`; CLI callers can ignore the return. |
| **Test surface** | (+) Each Responder now has CLI + JSON test paths, increasing confidence. |

## 6. Implementation Plan

* [x] Create `OutputFormat` enum, `AgentJsonResponse` DTO, `DtoSerializer` service, `AgentCommandResponder`.
* [x] Add `OutputFormat` parameter and JSON branch to all 12 DTO-based Responders.
* [x] Wire int/void commands through `AgentCommandResponder` in `castor.php`.
* [x] Remove `_run_agent_command` and all agent-mode closures from `castor.php`.
* [x] Extract serialisation tests from `AgentModeHelperTest` to `DtoSerializerTest`.
* [x] Add JSON output tests to all 12 Responder test files (success + error paths).
* [x] 100 % code coverage preserved.
