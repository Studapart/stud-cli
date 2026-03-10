# SCI-66 Implementation Report: Agent Mode (--agent) with JSON I/O

## Ticket

- **Key:** SCI-66
- **Title:** Add agent mode with JSON input and output for all commands
- **Goal:** When `--agent` is passed, input is a single JSON payload (stdin or one positional file path) and output is a single JSON object. Exit 0 on success, non-zero on failure.

## Completed Steps

### Phase 1: Discovery & Schema
- **`documentation/agent-mode-schema.json`** — full I/O schema for all 28 user-facing commands.

### Phase 2: Implementation

#### Shared Mechanism
- **`src/Exception/AgentModeException.php`** — domain exception for agent-mode errors.
- **`src/Service/AgentModeIoInterface.php`** — injectable I/O contract for testing.
- **`src/Service/AgentModeHelper.php`** — read JSON input (file/stdin/TTY-safe), build payloads, serialize DTOs, write JSON output.
- **`castor.php` helpers** — `_get_agent_mode_helper()`, `_agent_output_and_exit()`, `_run_agent_command()`.

#### Per-Command Wiring (all 28 commands)
Every user-facing command now has `--agent` option wired:

| Category | Commands |
|----------|----------|
| Config | config:init, config:show, config:validate |
| Jira Info (Response DTOs) | projects:list, filters:list, items:list, items:search, filters:show, items:show, items:create |
| Jira Actions | items:transition |
| Git Workflow | items:start, items:takeover, branch:rename, branches:list, branches:clean, commit, please, commit:undo, flatten, cache:clear, submit, pr:comment, pr:comments |
| Other | help, status, release, deploy, update |

- Commands with existing Response DTOs serialize structured data (issues, projects, filters, branches, comments, etc.) via `serializeDto()`/`serializeDtoList()`.
- Commands returning int/void capture exit code and return success/error JSON.
- Commands with required arguments made optional with validation guards for non-agent mode.
- `help --agent` reads and returns the full `agent-mode-schema.json` or a single command's schema.
- `update --agent` returns an explicit error (self-update is not meaningful for agents).

### Tests
- **`tests/Service/AgentModeHelperTest.php`** — 23 tests covering: file/stdin/IO reading, TTY detection, invalid JSON, non-object JSON, payload construction, DTO serialization (scalars, nested objects, DateTimes, arrays of objects), and output writing.
- **`tests/Exception/AgentModeExceptionTest.php`** — exception hierarchy test.
- **Total:** 1436 tests, 5584 assertions, **100% code coverage** (classes, methods, lines).

### Documentation
- **`documentation/adr-012-agent-mode-json-io.md`** — architecture decision record.

## Quality Gates

| Gate | Status |
|------|--------|
| PHPStan (level max) | PASS — 0 errors |
| PHP-CS-Fixer | PASS — 0 violations |
| PHPUnit | PASS — 1436 tests, 5584 assertions |
| Code Coverage | **100%** (94/94 classes, 649/649 methods, 5186/5186 lines) |

## Files Changed

| File | Action |
|------|--------|
| `documentation/adr-012-agent-mode-json-io.md` | Created |
| `src/Exception/AgentModeException.php` | Created |
| `src/Service/AgentModeIoInterface.php` | Created |
| `src/Service/AgentModeHelper.php` | Created |
| `src/Service/AgentModeSchemaGenerator.php` | Created (runtime reflection-based schema) |
| `castor.php` | Modified (all 28 task functions + 3 helpers) |
| `tests/bootstrap.php` | Modified (load castor.php for reflection) |
| `tests/Exception/AgentModeExceptionTest.php` | Created |
| `tests/Service/AgentModeHelperTest.php` | Created |
| `tests/Service/AgentModeSchemaGeneratorTest.php` | Created (cross-validates reflection vs source) |
| `cursor/reports/SCI-66-phase1-plan.md` | Updated |
