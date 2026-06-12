# Documentation Index

Central map for `stud-cli` documentation.

## User documentation

- [Setup overview](setup/index.md)
- [Configuration](setup/configuration.md)
- [Feature overview](features/index.md)
- [Workflow playbook](features/workflow-playbook.md)
- [Automation and agent mode](features/automation.md)
- [Command reference](reference/commands.md) (generated — run `stud docs:generate` after command changes)

## Integrations

- [GitHub](integrations/github.md)
- [GitLab](integrations/gitlab.md)

## Contributor documentation

- [Development guide](development/index.md)
- [Engineering conventions](../CONVENTIONS.md)
- [AI development protocol](../AI.md)
- [Contributing](../CONTRIBUTING.md)

## Architecture Decision Records

| ADR | Topic |
|-----|-------|
| [001](adr-001-filesystem-abstraction-with-flysystem.md) | FileSystem abstraction (Flysystem) |
| [002](adr-002-test-environment-detection.md) | Test environment detection |
| [003](adr-003-path-security-and-validation.md) | Path security and validation |
| [004](adr-004-test-safety-and-isolation.md) | Test safety and isolation |
| [005](adr-005-responder-pattern-architecture.md) | Responder pattern architecture |
| [006](adr-006-command-naming-convention.md) | Command naming (`object:verb`) |
| [007](adr-007-migration-system-architecture.md) | Configuration migration system |
| [008](adr-008-visibility-and-testability-conventions.md) | Visibility and testability |
| [009](adr-009-service-locator-pattern-in-castor.md) | Service locator in castor.php |
| [010](adr-010-internationalization-strategy.md) | Internationalization strategy |
| [011](adr-011-code-quality-metrics-and-enforcement.md) | Code quality metrics |
| [012](adr-012-agent-mode-json-io.md) | Agent mode JSON I/O |
| [013](adr-013-responder-based-dual-output.md) | Responder-based dual output |
| [014](adr-014-runtime-output-schema-via-attributes.md) | Runtime output schema via attributes |
| [015](adr-015-git-repository-decomposition.md) | GitRepository decomposition |
| [016](adr-016-item-create-handler-decomposition.md) | ItemCreateHandler decomposition |
| [017](adr-017-response-owned-output-and-diagnostics.md) | Response-owned output and diagnostics |
| [018](adr-018-presentation-owned-translation.md) | Presentation-owned translation |

New ADRs: use [adr-template.md](adr-template.md).
