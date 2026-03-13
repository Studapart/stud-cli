# [ADR-012] Agent Mode: JSON-Only I/O for All Commands

* **Status:** `Accepted` — implementation partially superseded by [ADR-013] and [ADR-014]
* **Date:** 2026-03-10
* **Authors:** AI Agent
* **Deciders:** Project Lead
* **Technical Context:** Castor CLI, PHP 8.4, stud-cli

## 1. Context and Problem Statement

AI coding assistants and automation pipelines need to call stud-cli commands programmatically and parse their output reliably.

* **The Pain Point:** Human-readable CLI output (tables, colours, prompts) is fragile to parse and breaks when formatting changes.
* **The Goal:** Every user-facing command accepts a single JSON payload as input and emits a single JSON object as output when the `--agent` flag is present.

## 2. Decision Drivers & Constraints

* **No new dependencies:** The feature must work with PHP's built-in `json_encode`/`json_decode`.
* **Backward compatibility:** Existing CLI behaviour is untouched when `--agent` is absent.
* **Testability:** 100 % code coverage must be maintained; all I/O paths must be mockable.
* **TTY safety:** Running `--agent` on an interactive terminal must not hang waiting for stdin.

## 3. Considered Options

* **Option 1:** Central `AgentModeHelper` service injected into each Castor task via a shared `_run_agent_command()` orchestrator.
* **Option 2:** Symfony Console `--format=json` output formatter at the framework level.
* **Option 3:** Separate binary / entry-point dedicated to agent mode.

## 4. Decision Outcome

**Chosen Option:** `Option 1 – Central AgentModeHelper + _run_agent_command orchestrator`

**Justification:**
Castor tasks are plain PHP functions, not Symfony Console commands, so framework-level formatters (Option 2) do not apply. A separate binary (Option 3) duplicates bootstrapping logic and increases maintenance cost. Option 1 keeps agent mode as a thin serialisation/deserialisation layer at the task boundary while reusing existing Handlers and Response DTOs unchanged.

## 5. Consequences (Trade-offs)

| Aspect | Result |
| --- | --- |
| **Backward compatibility** | (+) No change for users who omit `--agent`. |
| **Maintainability** | (+) One helper class; per-command wiring is 5–15 lines. |
| **Testability** | (+) Dependency injection for stdin, file reader, and TTY check. |
| **Complexity** | (-) Every new command must add an `--agent` branch. |
| **Schema drift** | (+) Schema generated at runtime via reflection — impossible to drift. |

## 6. Implementation Plan

* [x] Create `AgentModeHelper`, `AgentModeIoInterface`, and `AgentModeException`.
* [x] Add `_run_agent_command` orchestrator in `castor.php`.
* [x] Wire `--agent` into all 28 user-facing commands.
* [x] `AgentModeSchemaGenerator`: runtime reflection-based schema generation (no static JSON file).
* [x] `stud help --agent` outputs the auto-generated schema.
* [x] Schema validation test cross-references generator output against source-parsed task definitions.
* [x] Unit tests for `AgentModeHelper` (serialize, read, write, TTY) and `AgentModeSchemaGenerator`.
* [x] 100 % code coverage preserved.
