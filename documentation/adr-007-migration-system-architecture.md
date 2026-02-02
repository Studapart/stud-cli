# [ADR-007] Configuration Migration System Architecture

* **Status:** `Accepted`
* **Date:** 2026-02-02
* **Authors:** Engineering Team
* **Deciders:** Head of Engineering
* **Technical Context:** PHP 8.2+, Symfony 7.3, YAML configuration

## 1. Context and Problem Statement

**The Pain Point:** As `stud-cli` evolves, configuration formats need to change. Without a migration system:
- **Breaking Changes:** Users would need to manually update config files
- **Version Incompatibility:** Old configs would break with new code
- **User Friction:** Manual migration steps create support burden
- **Error-Prone:** Users might make mistakes during manual migration

**The Goal:** Create an automatic migration system that:
- Transforms old configuration formats to new formats automatically
- Runs migrations transparently during tool updates
- Supports both global and project-specific configurations
- Provides clear feedback during migration execution
- Handles migration failures gracefully

## 2. Decision Drivers & Constraints

* **Backward Compatibility:** Must support old config formats
* **User Experience:** Migrations should be automatic and transparent
* **Reliability:** Migration failures should not break the tool
* **Flexibility:** Support both global and project-specific migrations
* **Maintainability:** Easy to add new migrations
* **Testability:** Migrations must be testable in isolation

## 3. Considered Options

* **Option 1:** Manual migration instructions in CHANGELOG
  * Pros: Simple, no code needed
  * Cons: User friction, error-prone, support burden

* **Option 2:** Single migration script that runs once
  * Pros: Simple, runs automatically
  * Cons: Doesn't handle incremental updates, hard to test

* **Option 3:** Versioned migration system with discovery (Chosen)
  * Pros: Automatic, incremental, testable, supports multiple scopes
  * Cons: More complex, requires migration registry

* **Option 4:** Configuration schema validation only
  * Pros: Simple, catches errors early
  * Cons: Doesn't transform data, still requires manual updates

## 4. Decision Outcome

**Chosen Option:** `Option 3 - Versioned Migration System with Discovery`

**Justification:**
We chose a versioned migration system because:
1. **Automatic:** Migrations run automatically when config version is outdated
2. **Incremental:** Only pending migrations run, not all migrations
3. **Flexible:** Supports both global and project-specific migrations
4. **Testable:** Each migration is a class that can be tested independently
5. **Maintainable:** Easy to add new migrations by creating new classes
6. **Reliable:** Migration failures are handled gracefully with clear error messages
7. **Prerequisite Support:** Some migrations can be marked as prerequisites (must run during `stud update`)

The system consists of:
- **Migration Classes:** Implement `MigrationInterface`, extend `AbstractMigration`
- **Migration Registry:** Discovers and manages migrations
- **Migration Executor:** Executes migrations and updates config version
- **Migration Scope:** Global (affects `~/.config/stud/config.yml`) or Project (affects `.git/stud.config`)

## 5. Consequences (Trade-offs)

| Aspect | Result (Positive / Negative / Neutral) |
| --- | --- |
| **User Experience** | *(+) Automatic migrations, no manual steps required* |
| **Backward Compatibility** | *(+) Old configs automatically upgraded* |
| **Maintainability** | *(+) Easy to add new migrations, clear structure* |
| **Testability** | *(+) Each migration can be tested independently* |
| **Complexity** | *(-) Migration system adds architectural complexity* |
| **Performance** | *(Neutral) Migrations run once per config, minimal overhead* |
| **Error Handling** | *(+) Graceful failure handling, clear error messages* |

## 6. Implementation Plan

* [x] Create `MigrationInterface` and `AbstractMigration` base class
* [x] Create `MigrationScope` enum (GLOBAL, PROJECT)
* [x] Create `MigrationRegistry` for discovery and filtering
* [x] Create `MigrationExecutor` for execution
* [x] Implement global migration discovery
* [x] Implement project migration discovery
* [x] Add migration execution to config pass listener
* [x] Add prerequisite migration support for `stud update`
* [x] Create example migrations
* [x] Document migration creation process

---

### Implementation Details

**Migration Structure:**

```php
class Migration202501150000001_GitTokenFormat extends AbstractMigration
{
    public function getId(): string
    {
        return '202501150000001';
    }
    
    public function getDescription(): string
    {
        return 'Migrates GIT_TOKEN to GITHUB_TOKEN and GITLAB_TOKEN';
    }
    
    public function getScope(): MigrationScope
    {
        return MigrationScope::GLOBAL;
    }
    
    public function isPrerequisite(): bool
    {
        return true; // Must run during stud update
    }
    
    public function up(array $config): array
    {
        if (isset($config['GIT_TOKEN'])) {
            $config['GITHUB_TOKEN'] = $config['GIT_TOKEN'];
            unset($config['GIT_TOKEN']);
        }
        return $config;
    }
    
    public function down(array $config): array
    {
        // Optional rollback
        if (isset($config['GITHUB_TOKEN'])) {
            $config['GIT_TOKEN'] = $config['GITHUB_TOKEN'];
        }
        return $config;
    }
}
```

**Migration Discovery:**
- Migrations are discovered by scanning `src/Migrations/GlobalMigrations/` and `src/Migrations/ProjectMigrations/`
- Sorted by ID (timestamp-based: `YYYYMMDDHHIISS001`)
- Filtered based on current `migration_version` in config

**Migration Execution:**
- Global migrations run via config pass listener (before command execution)
- Prerequisite migrations run during `stud update` (before binary replacement)
- Project migrations run via config pass listener (when in git repository)
- Each migration updates `migration_version` after successful execution

**Migration ID Format:**
- Format: `YYYYMMDDHHIISS001` (timestamp + sequence number)
- Ensures proper ordering
- Example: `202501150000001` (January 15, 2025, 00:00:00, sequence 001)

**Prerequisite Migrations:**
- Marked with `isPrerequisite(): bool` returning `true`
- Must run during `stud update` before binary replacement
- Failures prevent update completion
- Used for breaking changes that must be applied before new code runs

**Non-Prerequisite Migrations:**
- Run on-demand when commands execute
- Failures log errors but don't block command execution
- Used for non-breaking changes or optional enhancements

---

### Pro-Tip for Symfony 7.4 ADRs

This decision follows **Database Migration Patterns** adapted for configuration. The migration system uses **Composition over Inheritance** (migrations compose behavior rather than inheriting complex logic) and **Interface Segregation** (MigrationInterface defines only what's needed). This aligns with Symfony 7.4's preference for explicit, testable patterns over magic or convention-based approaches.
