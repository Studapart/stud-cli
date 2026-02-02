# [ADR-002] Multi-Method Test Environment Detection Strategy

* **Status:** `Accepted`
* **Date:** 2026-02-02
* **Authors:** Engineering Team
* **Deciders:** Head of Engineering
* **Technical Context:** PHP 8.2+, PHPUnit 11.0, Symfony 7.3

## 1. Context and Problem Statement

**The Pain Point:** The codebase needed to detect when running in a test environment to prevent tests from accidentally modifying real user configuration files. Initial attempts used simple checks (e.g., `APP_ENV=test` or `PHPUNIT` constant) that could be:
- Easily spoofed in production
- Unreliable across different test runners
- Missing in certain test scenarios (e.g., integration tests, custom test runners)

**The Goal:** Create a robust, multi-layered test environment detection mechanism that:
- Prevents false positives (production code thinking it's in tests)
- Prevents false negatives (tests not being detected)
- Works across all test scenarios (unit tests, integration tests, custom runners)
- Cannot be easily spoofed in production

## 2. Decision Drivers & Constraints

* **Test Safety:** Must prevent tests from writing to real `~/.config/stud/config.yml` files
* **Reliability:** Must work with PHPUnit, custom test runners, and integration test scenarios
* **Security:** Must not be easily spoofable in production environments
* **Performance:** Detection should be fast (cached or early return)
* **Maintainability:** Should be easy to understand and debug
* **Symfony Best Practices:** Use constants and environment variables appropriately

## 3. Considered Options

* **Option 1:** Single explicit constant (`STUD_CLI_TEST_MODE`)
  * Pros: Simple, explicit, cannot be spoofed
  * Cons: Requires manual setup in every test, might be forgotten

* **Option 2:** Check for PHPUnit class existence only
  * Pros: Simple, works for most cases
  * Cons: Doesn't work if PHPUnit isn't loaded, can fail in some scenarios

* **Option 3:** Check environment variables only (`APP_ENV=test`, `PHPUNIT`)
  * Pros: Simple, standard approach
  * Cons: Can be spoofed, not always set by test runners

* **Option 4:** Multi-method detection with priority order (Chosen)
  * Pros: Robust, works in all scenarios, has fallbacks
  * Cons: More complex, requires understanding priority order

* **Option 5:** Check backtrace for PHPUnit test methods
  * Pros: Runtime detection, works even if constants aren't set
  * Cons: Performance overhead, can be fragile

## 4. Decision Outcome

**Chosen Option:** `Option 4 - Multi-Method Detection with Priority Order`

**Justification:**
We chose a multi-method approach because:
1. **Reliability:** Multiple detection methods provide redundancy - if one fails, others catch it
2. **Explicit Control:** Primary method (explicit constant) gives developers full control
3. **Runtime Detection:** Backtrace checking catches cases where constants aren't set
4. **Framework Integration:** Works with PHPUnit's class loading and environment setup
5. **Defense in Depth:** Multiple layers prevent both false positives and false negatives

The priority order ensures:
- Most reliable method (explicit constant) is checked first
- Runtime detection (backtrace) catches edge cases
- Framework detection (PHPUnit class) provides fallback
- Environment variables are last resort (can be spoofed but unlikely in practice)

## 5. Consequences (Trade-offs)

| Aspect | Result (Positive / Negative / Neutral) |
| --- | --- |
| **Test Safety** | *(+) Multiple detection methods ensure tests are always detected, preventing real file modification* |
| **Reliability** | *(+) Works across all test scenarios (unit, integration, custom runners)* |
| **Security** | *(+) Explicit constant check first prevents spoofing, other methods are fallbacks* |
| **Performance** | *(Neutral) Early return on first match, backtrace only checked if needed* |
| **Complexity** | *(-) Multiple detection methods increase cognitive load, requires documentation* |
| **Maintainability** | *(-) Need to understand priority order and when each method applies* |
| **Debugging** | *(Neutral) Can be harder to debug which method triggered, but all methods are explicit* |

## 6. Implementation Plan

* [x] Define `STUD_CLI_TEST_MODE` constant in `tests/bootstrap.php`
* [x] Configure constant in `phpunit.xml.dist`
* [x] Implement `isTestEnvironment()` method with 6 detection methods
* [x] Add explicit constant check as Method 1 (highest priority)
* [x] Add backtrace checking as Method 2 (runtime detection)
* [x] Add PHPUnit class existence check as Method 3
* [x] Add PHPUNIT constant check as Method 4
* [x] Add PHPUNIT environment variable check as Method 5
* [x] Add APP_ENV=test check as Method 6 (lowest priority)
* [x] Update `getConfigPath()` to return test path in test environment
* [x] Update `runPrerequisiteMigrations()` to skip migrations in test environment
* [x] Document detection priority order in code comments

---

### Implementation Details

**Detection Method Priority:**

1. **Explicit Constant (`STUD_CLI_TEST_MODE`):** Set in `tests/bootstrap.php` and `phpunit.xml.dist`. Most reliable, cannot be spoofed.

2. **Backtrace Analysis:** Checks if called from PHPUnit test class or test method. Catches runtime scenarios.

3. **PHPUnit Class Existence:** Checks if `PHPUnit\Framework\TestCase` class is loaded. Works for most test scenarios.

4. **PHPUNIT Constant:** Some test runners define this constant.

5. **PHPUNIT Environment Variable:** Some CI/CD systems set this.

6. **APP_ENV=test:** Common Symfony pattern, checked last as it can be spoofed.

**Example Implementation:**

```php
protected function isTestEnvironment(): bool
{
    // Method 1: Explicit constant (most reliable)
    if (defined('STUD_CLI_TEST_MODE') && STUD_CLI_TEST_MODE === true) {
        return true;
    }

    // Method 2: Backtrace check (runtime detection)
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);
    foreach ($backtrace as $frame) {
        if (isset($frame['class']) && str_contains($frame['class'], 'PHPUnit')) {
            return true;
        }
        // ... additional backtrace checks
    }

    // Methods 3-6: Additional fallback checks
    // ...
}
```

**Usage in Code:**

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

---

### Pro-Tip for Symfony 7.4 ADRs

This decision follows **Defense in Depth** security principles by using multiple detection layers. While Symfony 7.4 favors **Attributes over Configuration**, this ADR uses constants and environment variables appropriately for test environment detection, which is a cross-cutting concern that benefits from explicit configuration.
