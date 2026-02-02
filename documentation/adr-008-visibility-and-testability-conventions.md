# [ADR-008] Visibility Modifiers and Testability Conventions

* **Status:** `Accepted`
* **Date:** 2026-02-02
* **Authors:** Engineering Team
* **Deciders:** Head of Engineering
* **Technical Context:** PHP 8.2+, PHPUnit 11.0, PSR-12

## 1. Context and Problem Statement

**The Pain Point:** Traditional visibility conventions (`private` for internal methods) create testability challenges:
- **Complex Mocking:** Testing `private` methods requires complex reflection or integration tests
- **Over-Mocking:** Testing through public API only can require excessive mocking
- **Test Coverage:** Hard to achieve 100% coverage when internal logic is `private`
- **Refactoring Risk:** Changing `private` method signatures breaks tests unnecessarily

**The Goal:** Establish visibility conventions that:
- Enable direct unit testing of internal logic
- Maintain encapsulation where appropriate
- Support 100% test coverage goal
- Balance testability with encapsulation
- Follow PSR-12 and SOLID principles

## 2. Decision Drivers & Constraints

* **Testability:** Must support 100% test coverage goal
* **Encapsulation:** Must maintain appropriate encapsulation
* **PSR-12 Compliance:** Must follow PHP coding standards
* **SOLID Principles:** Must support Single Responsibility and Open/Closed principles
* **Maintainability:** Conventions should be clear and consistent
* **Team Agreement:** All developers must understand and follow conventions

## 3. Considered Options

* **Option 1:** Traditional approach (`private` for all internal methods)
  * Pros: Strong encapsulation, standard approach
  * Cons: Hard to test, requires complex mocking or integration tests

* **Option 2:** `protected` for testable methods, `private` for trivial helpers (Chosen)
  * Pros: Enables direct testing, maintains encapsulation, clear convention
  * Cons: Slightly weaker encapsulation than `private`

* **Option 3:** All methods `public` for maximum testability
  * Pros: Maximum testability
  * Cons: No encapsulation, violates OOP principles

* **Option 4:** Use reflection in tests to access `private` methods
  * Pros: Strong encapsulation
  * Cons: Fragile tests, complex setup, violates encapsulation anyway

## 4. Decision Outcome

**Chosen Option:** `Option 2 - protected for Testable Methods, private for Trivial Helpers`

**Justification:**
We chose `protected` for testable methods because:
1. **Testability:** Enables direct unit testing without complex mocking
2. **Encapsulation:** Still maintains encapsulation (not `public`)
3. **100% Coverage:** Supports goal of 100% test coverage
4. **Refactoring Safety:** Tests can verify internal logic directly
5. **Clear Convention:** Easy to understand and apply consistently
6. **PSR-12 Compliant:** Follows PHP coding standards

**Visibility Rules:**
- **`public`:** Public API methods that external code should interact with
- **`protected`:** Internal helper methods with logic that should be testable
- **`private`:** Trivial, one-line helpers with no meaningful logic to test

## 5. Consequences (Trade-offs)

| Aspect | Result (Positive / Negative / Neutral) |
| --- | --- |
| **Testability** | *(+) Direct unit testing of internal logic, easier to achieve 100% coverage* |
| **Encapsulation** | *(Neutral) Slightly weaker than `private`, but still encapsulated* |
| **Maintainability** | *(+) Clear convention, easy to understand* |
| **Refactoring** | *(+) Tests can verify internal logic, safer refactoring* |
| **Code Review** | *(+) Clear guidelines for visibility decisions* |
| **OOP Purity** | *(Neutral) Slightly less strict encapsulation, but justified by testability* |

## 6. Implementation Plan

* [x] Document visibility conventions in `CONVENTIONS.md`
* [x] Establish `protected` as default for internal methods with logic
* [x] Use `private` only for trivial one-line helpers
* [x] Use `public` for public API methods
* [x] Update existing code to follow conventions
* [x] Enforce conventions in code review
* [x] Document rationale in code comments where needed

---

### Implementation Details

**Visibility Guidelines:**

1. **`public` Methods:**
   - Public API of the class
   - Methods that external code (including tests) should interact with
   - Example: `public function handle(SymfonyStyle $io): int`

2. **`protected` Methods:**
   - Internal helper methods with meaningful logic
   - Methods that should be unit tested directly
   - Example: `protected function downloadPhar(...): ?string`
   - Example: `protected function replaceBinary(...): int`

3. **`private` Methods:**
   - Trivial, one-line helpers with no meaningful logic
   - Simple transformations or getters
   - Example: `private function formatDate(string $date): string { return date('Y-m-d', strtotime($date)); }`

**Example:**

```php
class UpdateHandler
{
    // Public API - external code calls this
    public function handle(SymfonyStyle $io, bool $info = false): int
    {
        // ...
    }
    
    // Protected - has logic, should be testable
    protected function downloadPhar(array $pharAsset, string $repoOwner, string $repoName): ?string
    {
        // Complex logic that should be tested directly
        // ...
    }
    
    // Private - trivial helper, no meaningful logic
    private function formatVersion(string $version): string
    {
        return ltrim($version, 'v');
    }
}
```

**Testing Strategy:**

```php
// Test protected method directly
public function testDownloadPhar(): void
{
    $handler = new UpdateHandler(...);
    $result = $handler->downloadPhar($asset, 'owner', 'repo');
    $this->assertNotNull($result);
}

// Test private method through public API (if trivial)
// Or test indirectly through protected/public methods
```

**Rationale:**
- **100% Coverage Goal:** Making complex methods `protected` enables direct testing
- **Reduced Mocking:** Don't need to mock entire dependency chains
- **Faster Tests:** Direct unit tests are faster than integration tests
- **Safer Refactoring:** Tests verify internal logic, not just public API

**Exception:**
- DTOs and value objects can use `private` properties with public getters
- These are simple data containers, not business logic

---

### Pro-Tip for Symfony 7.4 ADRs

This decision prioritizes **Testability over Strict Encapsulation**. While Symfony 7.4 favors **Attributes over Configuration**, visibility modifiers are a language-level concern that benefits from explicit, pragmatic conventions. The `protected` approach balances encapsulation with the practical need for comprehensive testing, aligning with modern PHP testing practices.
