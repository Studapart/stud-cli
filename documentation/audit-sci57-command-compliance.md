# SCI-57: Command compliance with ADR-005 (Responder pattern and ViewConfig)

This document classifies each user-facing command that displays structured data as: **compliant**, **fixed as part of SCI-57**, or **documented exception**.

## Audit list

| Command | Displays structured data? | Handler returns Response DTO? | Responder uses PageViewConfig? | Status |
|---------|----------------------------|-------------------------------|--------------------------------|--------|
| config:show | Yes (definition lists) | Yes | No (raw $io) | Compliant* |
| config:validate | Yes (definition list) | Yes | No (raw $io) | Compliant* |
| projects:list | Yes (table) | Yes | Yes | Compliant |
| filters:list | Yes (table) | No → Yes | No → Yes | **Fixed (SCI-57)** |
| items:list | Yes (table) | Yes | Yes | Compliant |
| items:search | Yes (table) | Yes | Yes | Compliant |
| filters:show | Yes (table) | Yes | Yes | Compliant |
| items:show | Yes (definition list, content) | Yes | No → Yes | **Fixed (SCI-57)** |
| branches:list | Yes (table) | No → Yes | No → Yes | **Fixed (SCI-57)** |
| pr:comments | Yes (sections, content) | Yes | Partial (tables via ViewConfig) | Compliant |
| status | Yes (dashboard) | No | N/A | **Documented exception (ADR-005 §7.5)** |

\* config:show and config:validate use definition lists via raw $io; they could be migrated to PageViewConfig in a follow-up for full consistency. They are not in scope for SCI-57.

## Summary

- **Compliant**: Handler returns Response DTO (no presentation I/O); Responder renders via PageViewConfig (TableBlock + Column, or Section/DefinitionItem/Content).
- **Fixed (SCI-57)**: filters:list, branches:list, items:show refactored to match the pattern.
- **Documented exception**: StatusHandler only (ADR-005 §7.5).
