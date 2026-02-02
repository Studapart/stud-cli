# [ADR-001] FileSystem Abstraction with Flysystem

* **Status:** `Accepted`
* **Date:** 2026-02-02
* **Authors:** Engineering Team
* **Deciders:** Head of Engineering
* **Technical Context:** PHP 8.2+, Symfony 7.3, League Flysystem 3.0

## 1. Context and Problem Statement

**The Pain Point:** The codebase was using direct PHP file system operations (`file_exists()`, `file_get_contents()`, `file_put_contents()`, etc.) scattered throughout the codebase. This created several issues:

* **Testability:** Unit tests couldn't easily mock file operations, requiring real file system access
* **Flexibility:** Hard to switch between different storage backends (local, in-memory, cloud)
* **Security:** Direct file operations made path traversal vulnerabilities more likely
* **Consistency:** Different parts of the codebase handled file operations differently

**The Goal:** Create a unified, testable, and secure abstraction layer for all file system operations that supports both production (local filesystem) and testing (in-memory filesystem) scenarios.

## 2. Decision Drivers & Constraints

* **Symfony Best Practices:** Use dependency injection and service abstraction patterns
* **Testability:** Must support in-memory filesystem for unit tests without modifying real user files
* **Backward Compatibility:** Must work with existing code paths that use temp files (`/tmp/`)
* **Performance:** Minimal overhead compared to direct file operations
* **Security:** Must prevent path traversal attacks and validate all input paths
* **Ecosystem:** League Flysystem 3.0 is a mature, well-maintained library with excellent Symfony integration

## 3. Considered Options

* **Option 1:** Use League Flysystem with a custom abstraction layer
  * Pros: Industry-standard library, supports multiple adapters, excellent testability
  * Cons: Requires adapter type detection, some complexity with temp file handling

* **Option 2:** Create a pure custom abstraction without Flysystem
  * Pros: Full control, no external dependencies
  * Cons: Reinventing the wheel, more maintenance burden, less flexible

* **Option 3:** Use Symfony Filesystem component
  * Pros: Native Symfony component, no external dependency
  * Cons: Less flexible, doesn't support in-memory adapters well, limited abstraction

* **Option 4:** Keep direct file operations but add a thin wrapper
  * Pros: Minimal changes, no new dependencies
  * Cons: Doesn't solve testability, still vulnerable to path issues, inconsistent patterns

## 4. Decision Outcome

**Chosen Option:** `Option 1 - League Flysystem with Custom Abstraction Layer`

**Justification:**
We chose Flysystem because it provides:
1. **Unified Interface:** Single `FilesystemOperator` interface for all operations
2. **Adapter Pattern:** Easy switching between local filesystem (production) and in-memory filesystem (tests)
3. **Testability:** In-memory adapter allows tests to run without touching the real filesystem
4. **Security:** Built-in path handling and validation capabilities
5. **Industry Standard:** Widely used, well-maintained, and battle-tested
6. **Symfony Integration:** Works seamlessly with Symfony's dependency injection

The custom `FileSystem` service wraps Flysystem to:
- Add path validation (null bytes, control characters)
- Handle edge cases (temp files outside filesystem root)
- Provide high-level operations (parseFile, dumpFile) for common use cases
- Cache adapter type detection at construction time for performance

## 5. Consequences (Trade-offs)

| Aspect | Result (Positive / Negative / Neutral) |
| --- | --- |
| **Testability** | *(+) Tests can use in-memory filesystem, preventing accidental modification of real files* |
| **Security** | *(+) Centralized path validation and realpath() normalization prevent path traversal attacks* |
| **Maintainability** | *(+) Single abstraction point for all file operations, easier to modify behavior* |
| **Performance** | *(Neutral) Minimal overhead - adapter type cached at construction, operations are fast* |
| **Complexity** | *(-) Requires understanding Flysystem concepts and adapter detection logic* |
| **Dependency** | *(+) Adds League Flysystem dependency, but it's a mature, stable library* |
| **Backward Compatibility** | *(Neutral) Factory method `createLocal()` maintains existing usage patterns* |
| **Temp File Handling** | *(-) Requires special handling for `/tmp/` paths that are outside filesystem root* |

## 6. Implementation Plan

* [x] Create `FileSystem` service class wrapping Flysystem
* [x] Implement path validation (`validatePath()`)
* [x] Implement path traversal prevention (`isPathWithinRoot()` with `realpath()`)
* [x] Add adapter type detection with caching (`determineIfLocalFilesystem()`)
* [x] Handle temp files with native operations when outside root
* [x] Update all handlers to use `FileSystem` service via dependency injection
* [x] Create factory method `FileSystem::createLocal()` for production use
* [x] Update tests to use in-memory filesystem adapter
* [x] Add comprehensive test coverage for FileSystem service

---

### Implementation Details

**Key Design Decisions:**

1. **Cached Adapter Detection:** Adapter type is determined once at construction time using reflection, then cached. This avoids performance overhead while maintaining flexibility.

2. **Hybrid Approach for Temp Files:** For paths starting with `/tmp/` or paths outside the filesystem root, we use native PHP operations. This allows `hash_file()` and other native functions to work correctly while maintaining Flysystem abstraction for all other paths.

3. **Path Validation:** All public methods validate paths using `validatePath()`, rejecting null bytes and control characters at the entry point.

4. **Path Normalization:** Uses `realpath()` to normalize paths before comparison, preventing path traversal attacks through symlinks or `..` sequences.

**Example Usage:**

```php
// Production
$fileSystem = FileSystem::createLocal();

// Tests
$adapter = new InMemoryFilesystemAdapter();
$flysystem = new FlysystemFilesystem($adapter);
$fileSystem = new FileSystem($flysystem);

// Usage
$fileSystem->fileExists($path);
$fileSystem->parseFile($path);
$fileSystem->dumpFile($path, $data);
```

---

### Pro-Tip for Symfony 7.4 ADRs

This decision follows **Composition over Inheritance** principles by using Flysystem's adapter pattern rather than extending a base filesystem class. The `FileSystem` service composes a `FilesystemOperator` and adds additional behavior (validation, path normalization) without modifying Flysystem's core functionality.
