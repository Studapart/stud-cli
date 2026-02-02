# [ADR-003] Path Security and Validation Strategy

* **Status:** `Accepted`
* **Date:** 2026-02-02
* **Authors:** Engineering Team
* **Deciders:** Head of Engineering
* **Technical Context:** PHP 8.2+, Symfony 7.3, League Flysystem 3.0

## 1. Context and Problem Statement

**The Pain Point:** The codebase was accepting user-provided file paths without validation, creating security vulnerabilities:

* **Path Traversal:** Paths like `../../../etc/passwd` could potentially access files outside the intended directory
* **Null Byte Injection:** Paths containing null bytes (`\0`) could bypass validation checks
* **Control Characters:** Invalid control characters in paths could cause unexpected behavior
* **Symlink Attacks:** Paths through symlinks could bypass directory restrictions
* **Inconsistent Validation:** Different parts of the codebase handled path validation differently (or not at all)

**The Goal:** Implement comprehensive path validation and normalization to:
- Prevent path traversal attacks
- Reject malicious path patterns (null bytes, control characters)
- Normalize paths to resolve symlinks and `..` sequences
- Provide consistent validation across all file operations

## 2. Decision Drivers & Constraints

* **Security:** Must prevent path traversal and injection attacks
* **Compatibility:** Must work with existing code paths and temp file handling
* **Performance:** Validation should be fast and not add significant overhead
* **Usability:** Should provide clear error messages for invalid paths
* **Symfony Best Practices:** Use exceptions for validation failures, follow Symfony validation patterns
* **PHP Standards:** Use native PHP functions (`realpath()`, `str_contains()`) where appropriate

## 3. Considered Options

* **Option 1:** Use `realpath()` for all path normalization
  * Pros: Resolves symlinks, normalizes `..` sequences, built-in PHP function
  * Cons: Returns `false` for non-existent paths, requires fallback logic

* **Option 2:** String-based validation only (reject `..` and `/` patterns)
  * Pros: Simple, fast, no filesystem calls
  * Cons: Doesn't handle symlinks, can be bypassed with encoding tricks

* **Option 3:** Comprehensive validation with `realpath()` + string checks (Chosen)
  * Pros: Defense in depth, handles all attack vectors, provides clear errors
  * Cons: More complex, requires careful fallback handling

* **Option 4:** Use Flysystem's built-in path validation only
  * Pros: No custom code, uses library's validation
  * Cons: Flysystem doesn't validate for null bytes or control characters, less control

* **Option 5:** Whitelist approach (only allow specific path patterns)
  * Pros: Very secure, explicit allowed patterns
  * Cons: Too restrictive, hard to maintain, breaks legitimate use cases

## 4. Decision Outcome

**Chosen Option:** `Option 3 - Comprehensive Validation with realpath() + String Checks`

**Justification:**
We chose comprehensive validation because:
1. **Defense in Depth:** Multiple validation layers prevent different attack vectors
2. **Path Normalization:** `realpath()` resolves symlinks and normalizes `..` sequences, preventing traversal attacks
3. **Input Validation:** String checks reject null bytes and control characters before filesystem operations
4. **Clear Errors:** Specific exceptions (`InvalidArgumentException`) provide clear feedback
5. **Performance:** Validation happens once at method entry, minimal overhead
6. **Flexibility:** Fallback logic handles edge cases (non-existent paths, `getcwd()` failures)

The validation strategy:
- **Entry Point:** All public methods validate paths using `validatePath()`
- **Normalization:** Uses `realpath()` to normalize paths before comparison
- **Root Checking:** Validates paths are within the filesystem root using normalized paths
- **Error Handling:** Throws `InvalidArgumentException` for invalid paths, `RuntimeException` for filesystem errors

## 5. Consequences (Trade-offs)

| Aspect | Result (Positive / Negative / Neutral) |
| --- | --- |
| **Security** | *(+) Prevents path traversal, null byte injection, and symlink attacks* |
| **Reliability** | *(+) Consistent validation across all file operations* |
| **Performance** | *(Neutral) Validation overhead is minimal, happens once per method call* |
| **Complexity** | *(-) Requires understanding validation logic and fallback behavior* |
| **Error Messages** | *(+) Clear, specific exceptions help developers understand issues* |
| **Edge Cases** | *(Neutral) Fallback logic handles `realpath()` failures gracefully* |
| **Maintainability** | *(+) Centralized validation logic, easy to update rules* |

## 6. Implementation Plan

* [x] Implement `validatePath()` method to reject null bytes and control characters
* [x] Implement `isPathWithinRoot()` method using `realpath()` normalization
* [x] Add path validation to all public `FileSystem` methods
* [x] Cache `getcwd()` result at construction time for performance
* [x] Add fallback logic for `realpath()` failures
* [x] Update error handling to throw appropriate exceptions
* [x] Add comprehensive tests for path validation edge cases
* [x] Document validation rules and security considerations

---

### Implementation Details

**Validation Strategy:**

1. **Input Validation (`validatePath()`):**
   - Rejects paths containing null bytes (`\0`)
   - Rejects paths containing control characters (except newline/tab in specific contexts)
   - Throws `InvalidArgumentException` with clear error message

2. **Path Normalization (`isPathWithinRoot()`):**
   - Uses `realpath()` to resolve symlinks and normalize `..` sequences
   - Falls back to original path if `realpath()` fails (non-existent paths)
   - Compares normalized paths to prevent traversal attacks

3. **Root Boundary Checking:**
   - Validates paths are within the filesystem root (typically `getcwd()`)
   - Uses normalized paths for comparison
   - Handles edge cases (non-existent paths, `getcwd()` failures)

**Example Implementation:**

```php
private function validatePath(string $path): void
{
    // Reject null bytes (potential security issue)
    if (str_contains($path, "\0")) {
        throw new \InvalidArgumentException('Path contains null byte');
    }

    // Reject control characters
    if (preg_match('/[\x00-\x08\x0B-\x0C\x0E-\x1F]/', $path)) {
        throw new \InvalidArgumentException('Path contains invalid control characters');
    }
}

private function isPathWithinRoot(string $path): bool
{
    // Normalize paths using realpath() to prevent path traversal attacks
    $normalizedPath = realpath($path) ?: $path;
    $normalizedCwd = realpath($this->cachedCwd) ?: $this->cachedCwd;

    return str_starts_with($normalizedPath, $normalizedCwd . DIRECTORY_SEPARATOR)
        || $normalizedPath === $normalizedCwd;
}
```

**Security Considerations:**

- **Path Traversal:** Prevented by `realpath()` normalization and root boundary checking
- **Null Byte Injection:** Prevented by explicit null byte check
- **Symlink Attacks:** Prevented by `realpath()` resolving symlinks before comparison
- **Control Character Injection:** Prevented by regex validation

**Performance Considerations:**

- `getcwd()` is cached at construction time (called once, not on every validation)
- `realpath()` is only called for absolute paths that need root checking
- Validation happens at method entry, failing fast for invalid input

---

### Pro-Tip for Symfony 7.4 ADRs

This decision follows **Security by Design** principles by validating input at the entry point of all public methods. While Symfony 7.4 favors **Attributes over Configuration**, path validation is a security concern that benefits from explicit, imperative validation logic rather than declarative attributes.
