# [ADR-014] Runtime Output Schema via PHP Attributes

* **Status:** `Accepted`
* **Date:** 2026-03-10
* **Authors:** AI Agent
* **Deciders:** Project Lead
* **Technical Context:** PHP 8.4, Castor Framework, stud-cli, ADR-012 (Agent Mode), ADR-013 (Dual Output)

## 1. Context and Problem Statement

* **The Pain Point:** The `stud help --agent` command needs to describe each command's output shape so that AI agents and automation tools can parse responses without guessing. The initial ADR-012 implementation hard-coded a generic placeholder (`"data": "(command-specific)"`) in the schema because there was no mechanism to describe output structure per-command.
* **The Goal:** Each command's output schema is described declaratively alongside the command definition, and the schema generator produces a complete input + output contract at runtime — with zero risk of schema drift.

## 2. Decision Drivers & Constraints

* **Single source of truth:** Output shapes must be defined once, next to the command, and flow automatically into the generated schema.
* **No static files:** A static JSON schema file drifts the moment a DTO changes and is unavailable inside the PHAR (learned from ADR-012 experience).
* **PHP idioms:** PHP 8 Attributes are the standard mechanism for declarative metadata on functions and classes.
* **Reflection capability:** The `AgentModeSchemaGenerator` already uses reflection for input schemas — output should follow the same approach.
* **Two command categories:** DTO-based commands (have a Response class) and int/void commands (return only a status message) need different description strategies.

## 3. Considered Options

* **Option 1:** Manually maintain a static `agent-mode-schema.json` file.
  * Pros: Simple to read.
  * Cons: Drifts from code, not available in PHAR builds, no compile-time validation.

* **Option 2:** PHPDoc annotations parsed at runtime.
  * Pros: No new classes.
  * Cons: Fragile string parsing, no IDE support, not type-safe.

* **Option 3:** Custom `#[AgentOutput]` PHP Attribute with reflection (Chosen).
  * Pros: Type-safe, IDE-supported, co-located with the command, supports both DTO-based and explicit property maps.
  * Cons: One new Attribute class; every command needs an annotation.

## 4. Decision Outcome

**Chosen Option:** `Option 3 – #[AgentOutput] PHP Attribute`

**Justification:**
A custom Attribute provides a type-safe, IDE-friendly declaration that lives directly on the task function — impossible to forget when adding a command. The schema generator reflects on the attribute at runtime and, for DTO-based commands, further reflects on the Response class's public properties to build a complete property→type map. No static file to maintain, no drift, works identically in development and PHAR.

### Attribute Design

```php
#[\Attribute(\Attribute::TARGET_FUNCTION)]
final class AgentOutput
{
    public function __construct(
        public readonly ?string $responseClass = null,  // class-string for DTO reflection
        public readonly array   $properties = [],       // explicit property→type map
        public readonly ?string $description = null,     // human-readable output description
    ) {}
}
```

### Two Usage Modes

**DTO-based commands** — the generator reflects on the Response class:
```php
#[AsTask(name: 'items:list', ...)]
#[AgentOutput(
    responseClass: ItemListResponse::class,
    description: 'List of work items matching the filter',
)]
function items_list(...): void { ... }
```

Generated output schema:
```json
{ "success": true, "data": { "success": "bool", "items": "array", "errorMessage": "string|null" } }
```

**Int/void commands** — explicit property map:
```php
#[AsTask(name: 'commit', ...)]
#[AgentOutput(
    properties: ['message' => 'string'],
    description: 'Commit result',
)]
function commit(...): void { ... }
```

Generated output schema:
```json
{ "success": true, "data": { "message": "string" } }
```

### Schema Generator Extension

`AgentModeSchemaGenerator` gains three private methods:

| Method | Purpose |
|---|---|
| `buildOutputSchema` | Produces the full output block (description, success shape, error shape) |
| `resolveOutputProperties` | Dispatches to `reflectResponseClass` or returns explicit properties |
| `reflectResponseClass` | Reflects on a Response DTO's public properties to build the type map |

Commands without `#[AgentOutput]` gracefully degrade to `"data": "(undescribed)"`.

## 5. Consequences (Trade-offs)

| Aspect | Result |
|---|---|
| **Schema accuracy** | (+) Output schema is always in sync with actual DTO properties. |
| **Discoverability** | (+) `#[AgentOutput]` is visible in the IDE, co-located with the task. |
| **Developer workflow** | (+) Adding a new command: annotate with `#[AgentOutput]` — done. |
| **Test coverage** | (+) Schema generator tests validate that every annotated command's output resolves correctly. |
| **Annotation burden** | (-) Each of the 28 commands needs an `#[AgentOutput]` line. |
| **Reflection cost** | (Neutral) Only paid once per `stud help --agent` call; negligible. |

## 6. Implementation Plan

* [x] Create `src/Attribute/AgentOutput.php` with `responseClass`, `properties`, `description`.
* [x] Annotate all 28 task functions in `castor.php` (11 DTO-based, 17 int/void).
* [x] Extend `AgentModeSchemaGenerator` with `buildOutputSchema`, `resolveOutputProperties`, `reflectResponseClass`.
* [x] Add test fixtures for edge cases (no attribute, empty attribute).
* [x] Add schema generator tests: DTO output, explicit properties, error structure, fallback paths.
* [x] `AgentOutputTest` for the attribute itself.
* [x] 100 % code coverage preserved.
