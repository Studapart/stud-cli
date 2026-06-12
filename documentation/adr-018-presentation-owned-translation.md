# [ADR-018] Presentation-Owned Translation

* **Status:** `Accepted`
* **Date:** 2026-06-11
* **Authors:** AI Agent
* **Deciders:** Project Lead
* **Technical Context:** PHP 8.4, Castor Framework, ADR-005, ADR-013, ADR-017

## 1. Context and Problem Statement

The responder architecture separates application logic from presentation, but translated user-facing strings still appear in several handlers and services. This makes application logic locale-aware and prevents agent mode from choosing terse, machine-oriented wording for the same command result.

## 2. Decision Drivers

* **Responder ownership:** presentation decisions belong to responders and prompt presenters.
* **Agent mode:** agent output needs the same message keys rendered through an agent-oriented locale or domain.
* **Testability:** handlers should assert stable keys and parameters instead of localized prose.
* **Consistency:** translation should follow the same boundary as CLI/JSON rendering.

## 3. Decision Outcome

Translation is a presentation concern.

* Handlers and domain services return message references: translation key, parameters, optional fallback, and optional machine code.
* Responders translate message references for CLI output and agent JSON.
* Prompt services translate prompt message references before asking the user.
* `TranslationService` is allowed in responders, prompt services, help/schema generation, documentation generation, and dedicated presentation helpers.
* Handlers and domain services must not call `TranslationService::trans()` for user-visible output, diagnostics, prompts, or errors.

## 4. Message References

Responses and diagnostics carry `MessageRef` values for user-visible text:

* `key`: stable translation key.
* `parameters`: translation parameters.
* `fallback`: optional fallback for external or transitional messages.
* `code`: optional machine-readable error or diagnostic code.

Plain strings are reserved for external data that is already user-authored or provider-authored and is not a translation key.

## 5. Consequences

* Agent and CLI output can render the same response with different locale/domain choices.
* Handlers become less coupled to i18n and easier to test through stable keys.
* Migration requires response DTOs, diagnostics, responders, prompt services, and handler tests to accept message references.
* Human CLI wording remains controlled by existing translation files until an agent-specific message locale is introduced.

## 6. Cross-References

This ADR extends ADR-005 and ADR-017 by making localized text part of presentation rendering, not domain/application result construction.
