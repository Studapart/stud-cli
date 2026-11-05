# [ADR-011] Code Quality Metrics and Enforcement Strategy

* **Status:** `Accepted`
* **Date:** 2026-02-02
* **Authors:** Engineering Team
* **Deciders:** Head of Engineering
* **Technical Context:** PHP 8.4.1+, PHPStan, PHP-CS-Fixer, PHPUnit

## 1. Context and Problem Statement

**The Pain Point:** Without defined quality metrics and enforcement:
- **Technical Debt:** Code complexity grows unchecked
- **Inconsistency:** Different developers use different patterns
- **Maintainability:** Hard to maintain complex, large methods
- **Code Review:** Subjective quality discussions without clear standards
- **Refactoring Risk:** High complexity code is risky to refactor

**The Goal:** Establish measurable code quality metrics and enforcement mechanisms that:
- Prevent technical debt accumulation
- Ensure consistent code quality
- Provide clear, objective standards
- Enable automated enforcement where possible
- Support maintainable, testable code

## 2. Decision Drivers & Constraints

* **Maintainability:** Code must be easy to understand and modify
* **Testability:** Code must be testable (supports 100% coverage goal)
* **Team Standards:** All developers must follow same standards
* **Automation:** Metrics should be enforceable via tools
* **PSR-12 Compliance:** Must follow PHP coding standards
* **SOLID Principles:** Must support SOLID principles

## 3. Considered Options

* **Option 1:** No formal metrics, code review only
  * Pros: Flexible, no tooling needed
  * Cons: Subjective, inconsistent, technical debt accumulates

* **Option 2:** Comprehensive metrics with automated enforcement (Chosen)
  * Pros: Objective, consistent, prevents technical debt, automated
  * Cons: Requires tooling setup, can be strict

* **Option 3:** Basic metrics only (lines of code, complexity)
  * Pros: Simple, easy to enforce
  * Cons: Doesn't capture all quality aspects

* **Option 4:** Metrics as guidelines only (no enforcement)
  * Pros: Flexible
  * Cons: Not consistently followed, technical debt accumulates

## 4. Decision Outcome

**Chosen Option:** `Option 2 - Comprehensive Metrics with Automated Enforcement`

**Justification:**
We chose comprehensive metrics because:
1. **Objective Standards:** Clear, measurable thresholds prevent subjective discussions
2. **Technical Debt Prevention:** Catches complexity issues before they accumulate
3. **Automated Enforcement:** PHPMD, the CRAP check, PHPStan, and PHP-CS-Fixer enforce standards automatically
4. **Team Alignment:** Everyone follows same standards, consistent codebase
5. **Maintainability:** Enforces patterns that support maintainable code
6. **SOLID Support:** Metrics align with SOLID principles (SRP, OCP)

The metrics cover:
- **Complexity:** Cyclomatic Complexity (CC), CRAP Index, NPath, Nesting Depth
- **Cohesion:** LCOM4 (Lack of Cohesion of Methods)
- **Size:** Class size, Method size
- **Signatures:** Class properties, Method arguments

## 5. Consequences (Trade-offs)

| Aspect | Result (Positive / Negative / Neutral) |
| --- | --- |
| **Code Quality** | *(+) Consistent, maintainable code across codebase* |
| **Technical Debt** | *(+) Prevents accumulation of complex, hard-to-maintain code* |
| **Team Alignment** | *(+) Clear standards, no subjective discussions* |
| **Refactoring Safety** | *(+) Lower complexity = safer refactoring* |
| **Development Speed** | *(-) May require refactoring before feature completion* |
| **Tooling Setup** | *(-) Requires PHPMD, PHPStan, and PHP-CS-Fixer configuration* |
| **Learning Curve** | *(-) Developers must understand metrics* |

## 6. Implementation Plan

* [x] Define Project Quality Metric Blueprint in `CONVENTIONS.md`
* [x] Configure PHPMD for complexity and size detection
* [x] Configure PHP-CS-Fixer for code style enforcement
* [x] Document all metric thresholds
* [x] Create examples of good vs. bad code
* [x] Enforce metrics in code review
* [x] Update CI/CD to check metrics
* [x] Provide refactoring guidance for violations

---

### Implementation Details

**Project Quality Metric Blueprint:**

| Focus Area | Metric | Threshold | Enforcement |
|------------|--------|-----------|-------------|
| **COMPLEXITY** | Cyclomatic Complexity (CC) | Maximum 10 per method | PHPMD |
| | CRAP Index | Maximum 10 per class/method | Clover check |
| | NPath Complexity | Maximum 200 | PHPMD |
| | Nesting Depth | Maximum 3 | Code Review |
| **COHESION** | LCOM4 (Lack of Cohesion) | Maximum 2 | Code Review |
| **SIZE** | Class Size (Lines) | Maximum 400 lines of code | PHPMD |
| | Method Size (Lines) | Maximum 40 lines of code | PHPMD |
| **SIGNATURES** | Class Properties | Maximum 10 properties | PHPMD |
| | Method Arguments | Maximum 4 arguments | PHPMD |

**Enforcement Tools:**

1. **PHP-CS-Fixer:**
   - Enforces PSR-12 code style
   - Automatic formatting
   - Configuration: `.php-cs-fixer.dist.php`

2. **PHPStan:**
   - Static analysis (Level 7+)
   - Type checking
   - Configuration: `phpstan.neon.dist`
   - Does not enforce cyclomatic complexity, CRAP, NPath, or size

3. **PHPMD:**
   - Cyclomatic complexity, NPath, method length, class length, argument count, and property count
   - Configuration: `phpmd.xml`
   - Existing violations: `phpmd.baseline.xml`

4. **CRAP:**
   - `scripts/check-crap.php` reads the Clover report from the same PHPUnit run

5. **Code Review:**
   - Nesting depth
   - LCOM4
   - Promoted readonly DTO, Response, and Value Object constructors stay exempt from the 4-argument limit

**Refactoring Strategies:**

1. **High Complexity:**
   - Extract methods
   - Use early returns
   - Replace conditionals with polymorphism

2. **Large Methods:**
   - Extract smaller methods
   - Use composition
   - Break into logical steps

3. **Many Arguments:**
   - Use parameter objects (DTOs)
   - Use builder pattern
   - Extract configuration objects

4. **High Nesting:**
   - Use early returns
   - Extract methods
   - Use guard clauses

**Example Refactoring:**

```php
// ❌ BAD: CC > 10, nesting > 3, method > 40 lines
public function processData($data) {
    if ($condition1) {
        if ($condition2) {
            if ($condition3) {
                if ($condition4) {
                    // ... 50+ lines
                }
            }
        }
    }
}

// ✅ GOOD: CC ≤ 10, nesting ≤ 3, method ≤ 40 lines
public function processData(array $data): array
{
    if (!$this->validateData($data)) {
        return [];
    }
    return $this->transformData($data);
}

protected function validateData(array $data): bool
{
    // Simple validation (CC ≤ 10)
}

protected function transformData(array $data): array
{
    // Simple transformation (CC ≤ 10)
}
```

---

### Pro-Tip for Symfony 8.1 ADRs

This decision follows **Quality Gates** and **Continuous Integration** principles. While Symfony 8.1 favors **Attributes over Configuration**, code quality metrics are enforced through **PHPMD**, the **CRAP check**, **PHPStan**, and **PHP-CS-Fixer**, which align with Symfony 8.1's emphasis on **Tooling and Automation**. The metrics support **SOLID Principles** by enforcing complexity limits that naturally lead to better design.
