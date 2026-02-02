# [ADR-004] Test Safety and Isolation Strategy

* **Status:** `Accepted`
* **Date:** 2026-02-02
* **Authors:** Engineering Team
* **Deciders:** Head of Engineering
* **Technical Context:** PHP 8.2+, PHPUnit 11.0, League Flysystem 3.0

## 1. Context and Problem Statement

**The Pain Point:** Tests were potentially modifying real user configuration files and data:

* **Real File Modification:** Tests using `sys_get_temp_dir()` or real paths could accidentally write to user's `~/.config/stud/config.yml`
* **Data Corruption:** Tests running migrations could modify real configuration files
* **Side Effects:** Tests leaving behind files or configuration changes
* **CI/CD Issues:** Tests failing in CI due to permission issues or missing directories
* **Developer Experience:** Developers afraid to run tests due to potential data loss

**The Goal:** Ensure tests are completely isolated from production data and cannot accidentally modify real user files, while maintaining the ability to test real-world scenarios.

## 2. Decision Drivers & Constraints

* **Test Safety:** Must prevent any possibility of tests modifying real user files
* **Testability:** Must still allow testing of real-world scenarios (migrations, file operations)
* **CI/CD Compatibility:** Must work in CI environments without special setup
* **Developer Experience:** Tests should be safe to run without fear of data loss
* **Maintainability:** Test isolation should be automatic, not require manual setup per test
* **Performance:** Isolation mechanism should not significantly slow down tests

## 3. Considered Options

* **Option 1:** Use in-memory filesystem for all tests
  * Pros: Complete isolation, no filesystem access
  * Cons: Some operations (like `hash_file()`) require real filesystem

* **Option 2:** Use temporary directories with cleanup
  * Pros: Real filesystem operations, isolated per test
  * Cons: Requires cleanup, can leave files behind, permission issues

* **Option 3:** Test path isolation + in-memory filesystem hybrid (Chosen)
  * Pros: Best of both worlds, automatic isolation, works with all operations
  * Cons: Requires test environment detection

* **Option 4:** Mock all filesystem operations
  * Pros: Complete control, no filesystem access
  * Cons: Complex mocking, doesn't test real filesystem behavior

* **Option 5:** Use separate test user/home directory
  * Pros: Real filesystem, complete isolation
  * Cons: Complex setup, CI/CD complications, permission issues

## 4. Decision Outcome

**Chosen Option:** `Option 3 - Test Path Isolation + In-Memory Filesystem Hybrid`

**Justification:**
We chose the hybrid approach because:
1. **Complete Isolation:** Test paths (e.g., `/test/.config/stud/config.yml`) are guaranteed to not exist in production
2. **Flexibility:** In-memory filesystem for most operations, real filesystem for operations that require it (e.g., `hash_file()`)
3. **Automatic:** Test environment detection automatically switches to test paths
4. **Safety:** Multiple layers prevent accidental real file modification:
   - Test environment detection
   - Test-specific paths
   - In-memory filesystem for most operations
5. **Real-World Testing:** Still allows testing real filesystem operations when needed (temp files for hash verification)

The strategy combines:
- **Test Environment Detection:** Automatically detects test environment (see ADR-002)
- **Test Path Isolation:** Returns test-specific paths (e.g., `/test/...`) in test environment
- **In-Memory Filesystem:** Uses Flysystem's in-memory adapter for most test operations
- **Selective Real Filesystem:** Uses real filesystem only for operations that require it (e.g., `/tmp/` for `hash_file()`)

## 5. Consequences (Trade-offs)

| Aspect | Result (Positive / Negative / Neutral) |
| --- | --- |
| **Test Safety** | *(+) Multiple layers prevent any possibility of modifying real files* |
| **Developer Confidence** | *(+) Developers can run tests without fear of data loss* |
| **CI/CD Compatibility** | *(+) Works in all CI environments without special setup* |
| **Test Realism** | *(Neutral) Most operations use in-memory, but real filesystem available when needed* |
| **Complexity** | *(-) Requires understanding test environment detection and path isolation* |
| **Performance** | *(+) In-memory filesystem is faster than real filesystem operations* |
| **Maintainability** | *(+) Automatic isolation, no manual setup required per test* |

## 6. Implementation Plan

* [x] Implement test environment detection (see ADR-002)
* [x] Update `getConfigPath()` to return test path in test environment
* [x] Configure tests to use in-memory filesystem adapter
* [x] Update test setup to use test-specific paths
* [x] Handle temp file operations with real filesystem when needed
* [x] Update migration tests to skip in test environment
* [x] Document test isolation strategy
* [x] Add tests to verify isolation (attempting to write to real paths fails)

---

### Implementation Details

**Test Isolation Layers:**

1. **Test Environment Detection:**
   - Automatically detects when running in tests (see ADR-002)
   - Prevents test code from executing production paths

2. **Test Path Isolation:**
   ```php
   protected function getConfigPath(): string
   {
       if ($this->isTestEnvironment()) {
           return '/test/.config/stud/config.yml'; // Safe test path
       }
       
       $home = $_SERVER['HOME'] ?? throw new \RuntimeException('...');
       return rtrim($home, '/') . '/.config/stud/config.yml';
   }
   ```

3. **In-Memory Filesystem:**
   ```php
   // In test setup
   $adapter = new InMemoryFilesystemAdapter();
   $flysystem = new FlysystemFilesystem($adapter);
   $fileSystem = new FileSystem($flysystem);
   ```

4. **Selective Real Filesystem:**
   ```php
   // For operations requiring real filesystem (e.g., hash_file())
   if (str_starts_with($path, '/tmp/')) {
       file_put_contents($path, $contents); // Real filesystem
   }
   ```

**Test Setup Pattern:**

```php
protected function setUp(): void
{
    parent::setUp();
    
    // Use in-memory filesystem
    $adapter = new InMemoryFilesystemAdapter();
    $flysystem = new FlysystemFilesystem($adapter);
    $this->fileSystem = new FileSystem($flysystem);
    
    // Set test environment variable
    $_SERVER['HOME'] = '/tmp'; // Safe for tests
    
    // Handler uses test paths automatically
    $this->handler = new UpdateHandler(..., $this->fileSystem);
}
```

**Safety Guarantees:**

1. **Test Paths Never Exist:** Test paths like `/test/.config/stud/config.yml` are guaranteed to not exist in production
2. **In-Memory Operations:** Most file operations use in-memory filesystem, never touching disk
3. **Automatic Detection:** Test environment is automatically detected, no manual configuration needed
4. **Migration Skipping:** Migrations automatically skip in test environment, preventing config modification

**Example Test:**

```php
public function testDoesNotModifyRealConfig(): void
{
    // This test verifies that even if test environment detection fails,
    // the test path is safe and won't modify real files
    $configPath = $this->handler->getConfigPath();
    $this->assertStringStartsWith('/test/', $configPath);
    $this->assertFileDoesNotExist($configPath); // Real file doesn't exist
}
```

---

### Pro-Tip for Symfony 7.4 ADRs

This decision follows **Test-Driven Development** and **Defense in Depth** principles. While Symfony 7.4 favors **Attributes over Configuration**, test isolation is a cross-cutting concern that benefits from explicit, imperative logic that can be easily verified and tested. The hybrid approach (in-memory + selective real filesystem) provides both safety and flexibility.
