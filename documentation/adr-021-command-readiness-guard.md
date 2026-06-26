# ADR-021: Command Readiness Guard

* **Status:** Accepted
* **Date:** 2026-06-19
* **Authors:** stud-cli maintainers
* **Technical Context:** PHP 8.x, Castor tasks, `_config_pass_listener`, SCI-152 / SCI-143 epic

## 1. Context and Problem Statement

Central `ConfigValidator::COMMAND_REQUIREMENTS` duplicated provider logic, covered only three commands, and drifted from handler dependencies. Commands failed late in `_get_jira_config()` or `_get_git_hosting()` instead of at entry.

**Goal:** Decide **run vs block** before handler execution using runtime capability discovery aligned with handler needs.

## 2. Decision Drivers & Constraints

* Complement hexagonal outbound ports (SCI-159); do not replace them.
* Preserve interactive remediation (prompt + save config) from the existing config pass listener.
* Provider-aware requirements must reuse `GlobalConfigProviderResolver`.
* Agent / quiet mode must fail fast without prompts.

## 3. Considered Options

* **Option 1:** Expand `COMMAND_REQUIREMENTS` matrix for all commands.
* **Option 2:** `CommandGuard` + marker interfaces on handlers + `CommandHandlerRegistry`.
* **Option 3:** Full hexagonal use-case interfaces per command.

## 4. Decision Outcome

**Chosen Option:** Option 2 — Command readiness guard.

**Justification:** Handler marker interfaces are the single runtime truth for integration needs; guard policy stays centralized; incremental adoption without big-bang folder moves.

## 5. Architecture

```text
Castor task → CommandContextFactory → CommandGuard → handler
                     ↑                      ↑
              resolvers (config, env,     CapabilityDiscovery
               providers)               + CommandHandlerRegistry
```

| Component | Role |
|-----------|------|
| `CommandContext` | Immutable snapshot (config, git repo, providers, flags) |
| `ConfigResolver` | Global + project config snapshots (merge/fallbacks in Phase C) |
| `CapabilityDiscovery` | Reflect handler `*Aware` marker interfaces |
| `CommandHandlerRegistry` | Command name → handler class; explicit caps for inline tasks |
| `CommandGuard` | Match capabilities to context; provider-aware key rules |
| `ConfigRemediationService` | Interactive prompt + auto-detect (extracted from `ConfigValidator`) |

## 6. Consequences

| Aspect | Result |
|--------|--------|
| Maintainability | (+) No duplicate command→requirements map |
| Behavior | (+) Earlier, consistent blocking for ~30 commands |
| Complexity | (-) New `App\Guard\` namespace and registry upkeep |
| SCI-159 | (+) Compatible — markers stay when handlers move to ports |

## 7. Implementation

* Namespace: `src/Guard/`
* Listener: `_config_pass_listener` Step 3–4 uses guard + remediation
* Removed: `ConfigValidator::COMMAND_REQUIREMENTS`
* Whitelisted commands skip guard (init, help, cache:clear, etc.)

---

See also: `.cursor/reports/command-readiness-guard-architecture.md`

## 8. Cross-references

* [ADR-023](adr-023-integration-layering-and-naming.md) — ports, adapters, clients glossary and rename phases
