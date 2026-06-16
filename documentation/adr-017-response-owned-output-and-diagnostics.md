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

## 6. Recoverable Failures and Service-Boundary Exceptions

Domain code often hits non-fatal failures: a corrupt config file, a provider API timeout, a temp file already deleted during cleanup. These are recoverable outcomes, not reasons to abort the command. How they are surfaced depends on whether the caller is ambient or user-facing.

### Logger stays in presentation

* Do **not** inject `Logger` into handlers or domain services to report recoverable failures.
* Do **not** add optional `?Logger` service parameters as a debug workaround.
* CLI verbosity (`-v`, `-vv`, `-vvv`) is a responder concern. `WorkflowEntryRecorder` debug lines are workflow response records, not `Logger` calls.

Architecture tests enforce that handlers and non-presentation services must not `use App\Service\Logger`.

### Swallow at the service boundary for ambient reads

When many callers only need a best-effort result to continue, map recoverable failures to a neutral domain value at the service boundary:

* Example: `readProjectConfig()` returns `[]` when the file is missing or unparsable; callers treat that as “no project config”.
* Example: `deleteIfExists()` ignores cleanup errors after rebase temp scripts are no longer needed.

No diagnostics are required on these paths unless a specific command exposes the data to the user.

When callers may need to distinguish states without presentation side effects, return a structured domain result instead of logging:

* Example: `ConfigFileReadResult` separates missing, readable, and unreadable reads, while `readProjectConfig()` keeps the backward-compatible `[]` fallback.

### Surface diagnostics on inspect commands

When the user explicitly inspects state (`config:show`, `config:validate`, status-style commands), recoverable failures that would otherwise look like empty or missing data must become response diagnostics:

* Handlers attach `ResponseMessage::warning()` (or similar) with a `MessageRef` key and optional `technicalDetails` (parse error, API message).
* Responders render warnings at normal verbosity and `technicalDetails` at debug verbosity (`-vvv`); agent JSON includes the same diagnostics payload.

Example: `config:show` warns when global or project config exists but cannot be parsed, instead of silently showing empty config.

For workflow-style commands (`branches:clean`, `branches:rename`), record recoverable provider or git failures on `WorkflowEntryRecorder` at `VERBOSITY_DEBUG` (for example via `RecoverableExceptionLogger::logToRecorder()`), not via `Logger`.

### Decision summary

| Situation | Pattern |
|-----------|---------|
| Ambient read; empty fallback is correct | Swallow / neutral return at service boundary |
| User inspecting explicit state | Response diagnostics on the command response DTO |
| Workflow command with recorder in scope | Debug line on `WorkflowEntryRecorder` |
| Post-operation cleanup with no user impact | Swallow silently |

## 7. Consequences

* Agent JSON no longer loses warnings/errors that were formerly only visible through human logger output.
* CLI output remains responder-controlled and can continue to use `Logger` and `PageViewConfig`.
* Handler tests become more stable because they assert response state instead of output side effects.
* Migrating legacy commands requires broad but mechanical updates to handlers, responders, tests, and task wiring.

## 8. Cross-References

This ADR clarifies the output ownership model described in ADR-005 and extends the dual-output responder model from ADR-013. ADR-014 schemas should reflect response DTO fields and diagnostics where applicable.
