# [ADR-017] Response-Owned Output and Diagnostics

* **Status:** `Accepted`
* **Date:** 2026-06-10
* **Authors:** AI Agent
* **Deciders:** Project Lead
* **Technical Context:** PHP 8.4, Castor Framework, stud-cli, ADR-005, ADR-013, ADR-014

## 1. Context and Problem Statement

Agent mode requires stdout to contain exactly one JSON response, while CLI users need readable sections, tables, errors, warnings, and success messages. Some legacy handlers and services still decide what to print by calling `Logger` directly before a responder receives a command result. This creates two problems:

* meaningful warnings and errors can be lost when agent mode suppresses human output;
* presentation decisions are split between handlers, services, task functions, and responders.

## 2. Decision Drivers

* **ADR-005 compliance:** handlers own application logic and return response DTOs; responders own presentation.
* **ADR-013 compliance:** CLI and JSON presentation must share one responder-owned output path.
* **Agent reliability:** warnings, errors, and technical details must be represented in structured JSON instead of being printed before the final payload.
* **Schema visibility:** meaningful output must live in response DTOs so agent schemas can describe it.
* **Testability:** handlers should be tested through returned response state, not console output.

## 3. Decision Outcome

All meaningful command output belongs to concrete `AbstractResponse` DTOs.

* Handlers and domain services must not use `Logger` for user-visible output, warnings, errors, progress summaries, or diagnostics.
* Handlers return concrete response DTOs containing success data, errors, warnings, notices, and technical details.
* Responders render the same response DTO to CLI or agent JSON.
* `Logger` is a CLI rendering sink used by responders, ViewConfig, and rendering helpers. It does not decide what is meaningful.
* `AgentJsonResponse` remains a transport envelope produced by responders.
* Compact agent output follows SCI-126: completion-only success can omit `data`, but explicit errors and diagnostics are never hidden.

## 4. Diagnostics Model

Responses may carry diagnostics as structured messages:

* `Error`: blocking or non-blocking error information with optional technical details.
* `Warning`: important non-blocking issue that can affect follow-up work.
* `Notice`: useful user/agent context, such as skipped optional fields or empty states.
* `Info`: low-priority data only included when it is part of the response contract.

Progress text and decorative CLI structure should not become diagnostics unless it affects the command result.

## 5. Implementation Rules

* Prefer domain-specific responses when the command has reusable state.
* Use a generic `CommandResponse` only for simple side-effect commands without useful domain fields.
* Existing response DTOs should be extended with diagnostics instead of replaced.
* Task functions should parse input, call handlers, pass responses to responders, and exit based on `response->isSuccess()`.
* Direct `AgentJsonResponse` construction is reserved for bootstrap-level failures where a normal response/responder path cannot safely be built.
* Prompting is input collection, not output rendering. Interactive prompts should use a prompt abstraction rather than `Logger`.

## 6. Consequences

* Agent JSON no longer loses warnings/errors that were formerly only visible through human logger output.
* CLI output remains responder-controlled and can continue to use `Logger` and `PageViewConfig`.
* Handler tests become more stable because they assert response state instead of output side effects.
* Migrating legacy commands requires broad but mechanical updates to handlers, responders, tests, and task wiring.

## 7. Cross-References

This ADR clarifies the output ownership model described in ADR-005 and extends the dual-output responder model from ADR-013. ADR-014 schemas should reflect response DTO fields and diagnostics where applicable.
