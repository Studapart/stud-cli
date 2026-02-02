# Engineering Conventions for stud-cli

This document serves as the single source of truth for coding standards, visibility, and testing philosophy for all contributions to `stud-cli`. It ensures that all code is high-quality, maintainable, and highly testable.

## Related Architecture Decision Records (ADRs)

The conventions in this document are informed by architectural decisions documented in our ADRs. For deeper understanding of the "why" behind these conventions, refer to the relevant ADRs:

### Architecture & Patterns
- **[ADR-005: Responder Pattern Architecture](documentation/adr-005-responder-pattern-architecture.md)** - Explains the Action-Domain-Responder pattern used throughout the codebase
- **[ADR-006: Command Naming Convention](documentation/adr-006-command-naming-convention.md)** - Documents the `object:verb` command naming pattern
- **[ADR-007: Migration System Architecture](documentation/adr-007-migration-system-architecture.md)** - Details the configuration migration system
- **[ADR-009: Service Locator Pattern in castor.php](documentation/adr-009-service-locator-pattern-in-castor.md)** - Explains how services are provided via helper functions

### Conventions & Best Practices
- **[ADR-008: Visibility and Testability Conventions](documentation/adr-008-visibility-and-testability-conventions.md)** - Rationale for `protected` vs `private` visibility choices
- **[ADR-010: Internationalization Strategy](documentation/adr-010-internationalization-strategy.md)** - Translation system and locale handling
- **[ADR-011: Code Quality Metrics and Enforcement](documentation/adr-011-code-quality-metrics-and-enforcement.md)** - Detailed explanation of all quality metrics

### Technical Decisions
- **[ADR-001: FileSystem Abstraction with Flysystem](documentation/adr-001-filesystem-abstraction-with-flysystem.md)** - FileSystem service design
- **[ADR-002: Test Environment Detection](documentation/adr-002-test-environment-detection.md)** - Multi-method test detection strategy
- **[ADR-003: Path Security and Validation](documentation/adr-003-path-security-and-validation.md)** - Security measures for file operations
- **[ADR-004: Test Safety and Isolation](documentation/adr-004-test-safety-and-isolation.md)** - Test isolation strategy

All ADRs are located in the `documentation/` directory.

---

## Code Architecture

### Foundation: PSR-12 & SOLID

All code in `stud-cli` must adhere to:
- **PSR-12**: The PHP coding standard for consistent code style
- **SOLID Principles**: Object-oriented design principles that promote maintainability and extensibility

### The `final` Keyword

The `final` keyword must **not** be used on injectable services (Handlers, Providers) to ensure testability and extensibility. These classes may need to be extended or mocked in tests.

**Use `final` for:**
- Non-injected classes like DTOs (Data Transfer Objects)
- Enums
- Value objects that should not be extended

**Do NOT use `final` for:**
- Handler classes (e.g., `UpdateHandler`, `CommitHandler`)
- Service classes (e.g., `GitRepository`, `JiraService`, `GithubProvider`)
- Any class that is injected via dependency injection

### Visibility Guidelines

Visibility modifiers are a critical aspect of testability and encapsulation:

- **`public`**: For the testable "Public API" of a class. These are the methods that external code (including tests) should interact with.
  - Example: `public function handle(SymfonyStyle $io): int`

- **`protected`**: Our default choice for internal helper methods with logic. This allows them to be unit-tested directly while maintaining encapsulation.
  - Example: `protected function downloadPhar(...): ?string`
  - Example: `protected function replaceBinary(...): int`

- **`private`**: Only for trivial, one-line helpers that don't contain meaningful logic to test.
  - Example: `private function formatDate(string $date): string { return date('Y-m-d', strtotime($date)); }`

**Rationale**: By making complex methods `protected` instead of `private`, we enable direct unit testing of these methods without requiring complex mocking or integration tests. This aligns with our goal of 100% test coverage.

**See also:** [ADR-008: Visibility and Testability Conventions](documentation/adr-008-visibility-and-testability-conventions.md) for detailed rationale and examples.

### Code Quality and Complexity Standards

To maintain code quality and prevent technical debt, all code in `stud-cli` must adhere to the following measurable thresholds. These metrics are enforced through static analysis tools (PHPStan) and manual code review during the development process.

**See also:** [ADR-011: Code Quality Metrics and Enforcement](documentation/adr-011-code-quality-metrics-and-enforcement.md) for detailed explanation of each metric, enforcement strategies, and refactoring guidance.

#### Project Quality Metric Blueprint

The following table defines all quality thresholds that must be met:

| Focus Area | Metric | Threshold | Enforcement |
|------------|--------|-----------|-------------|
| **COMPLEXITY** | Cyclomatic Complexity (CC) | Maximum 10 per method | PHPStan, Code Review |
| | CRAP Index | Maximum 10 per class/method | PHPStan, Code Review |
| | NPath Complexity | Maximum 200 | Static Analysis |
| | Nesting Depth | Maximum 3 | Code Review |
| **COHESION** | LCOM4 (Lack of Cohesion) | Maximum 2 | Static Analysis |
| **SIZE** | Class Size (Lines) | Maximum 400 lines of code | Code Review |
| | Method Size (Lines) | Maximum 40 lines of code | Code Review |
| **SIGNATURES** | Class Properties | Maximum 10 properties | Code Review |
| | Method Arguments | Maximum 4 arguments | Code Review |

#### Complexity Metrics

##### Cyclomatic Complexity (CC)

**Rule**: Maximum Cyclomatic Complexity of **10** for any single method.

**What is Cyclomatic Complexity?** Cyclomatic Complexity measures the number of linearly independent paths through a program's source code. It's calculated by counting decision points (if statements, loops, switch cases, etc.) plus 1.

**Enforcement**: 
- During development (Phase 1 of the AI protocol), all methods that will be modified or created must be assessed for complexity.
- If a method exceeds CC of 10, it MUST be refactored before proceeding with feature implementation.
- Tools like PHPStan, PHPUnit's coverage reports, or static analysis tools can help measure complexity.

**Example of refactoring high complexity:**
```php
// ❌ BAD: High complexity (CC > 10)
public function processData($data) {
    if ($condition1) {
        if ($condition2) {
            if ($condition3) {
                // ... many nested conditions
            }
        }
    }
    // ... more conditions
}

// ✅ GOOD: Refactored into smaller methods (each CC ≤ 10)
public function processData($data) {
    if (!$this->validateData($data)) {
        return false;
    }
    return $this->transformData($data);
}

protected function validateData($data): bool {
    // Simple validation logic (CC ≤ 10)
}

protected function transformData($data) {
    // Simple transformation logic (CC ≤ 10)
}
```

##### CRAP Index

**Rule**: Maximum CRAP Index (Change Risk Analysis and Prediction) of **10** for any new or modified class or method.

**What is CRAP Index?** CRAP Index combines Cyclomatic Complexity with test coverage to predict the risk of changing code. The formula is: `CC² × (1 - coverage/100)³ + CC`

**Enforcement**:
- All new classes and methods must have CRAP Index ≤ 10.
- All modified classes and methods must maintain or improve their CRAP Index to stay ≤ 10.
- If a class or method exceeds CRAP Index of 10, it MUST be refactored (by reducing complexity or increasing test coverage) before proceeding.

**How to reduce CRAP Index**:
1. Reduce Cyclomatic Complexity (break down complex methods).
2. Increase test coverage (write more tests).
3. Extract complex logic into smaller, well-tested classes.

##### NPath Complexity

**Rule**: Maximum NPath Complexity of **200** for any single method.

**What is NPath Complexity?** NPath Complexity measures the number of acyclic execution paths through a method. It provides a more detailed view than Cyclomatic Complexity by considering all possible combinations of decision outcomes.

**Enforcement**:
- Static analysis tools can help identify methods with high NPath Complexity.
- Methods exceeding the threshold should be refactored into smaller, more focused methods.

##### Nesting Depth

**Rule**: Maximum nesting depth of **3** levels.

**What is Nesting Depth?** Nesting depth measures how deeply control structures (if, for, while, switch, etc.) are nested within each other.

**Enforcement**:
- Code review and static analysis tools can identify excessive nesting.
- Deeply nested code should be refactored using early returns, guard clauses, or method extraction.

**Example:**
```php
// ❌ BAD: Nesting depth > 3
public function process($data) {
    if ($condition1) {
        if ($condition2) {
            if ($condition3) {
                if ($condition4) { // Depth 4 - violates rule
                    // ...
                }
            }
        }
    }
}

// ✅ GOOD: Reduced nesting using early returns
public function process($data) {
    if (!$condition1) {
        return;
    }
    if (!$condition2) {
        return;
    }
    if (!$condition3) {
        return;
    }
    // Depth 1 - within threshold
    // ...
}
```

#### Cohesion Metrics

##### LCOM4 (Lack of Cohesion of Methods)

**Rule**: Maximum LCOM4 of **2** for any class.

**What is LCOM4?** LCOM4 measures how well the methods of a class are related to each other through shared instance variables. Lower values indicate better cohesion.

**Enforcement**:
- Static analysis tools can calculate LCOM4.
- Classes with high LCOM4 should be split into multiple, more cohesive classes.

#### Size Metrics

##### Class Size

**Rule**: Maximum **400 lines of code** per class (excluding comments and blank lines).

**Enforcement**:
- Code review and static analysis tools can measure class size.
- Large classes should be split into smaller, focused classes following the Single Responsibility Principle.

##### Method Size

**Rule**: Maximum **40 lines of code** per method (excluding comments and blank lines).

**Enforcement**:
- Code review and static analysis tools can measure method size.
- Large methods should be refactored into smaller, focused methods.

**Example:**
```php
// ❌ BAD: Method exceeds 40 lines
public function processData($data) {
    // ... 50+ lines of code
}

// ✅ GOOD: Split into smaller methods
public function processData($data) {
    $validated = $this->validateData($data);
    $transformed = $this->transformData($validated);
    return $this->saveData($transformed);
}
```

#### Signature Metrics

##### Class Properties

**Rule**: Maximum **10 properties** per class.

**Enforcement**:
- Code review can identify classes with too many properties.
- Classes with many properties may indicate violation of Single Responsibility Principle and should be refactored.

##### Method Arguments

**Rule**: Maximum **4 arguments** per method.

**Enforcement**:
- Code review and static analysis tools can identify methods with too many arguments.
- Methods with many arguments should be refactored using:
  - Parameter objects (DTOs)
  - Builder pattern
  - Method extraction

**Example:**
```php
// ❌ BAD: Too many arguments (> 4)
public function createUser($firstName, $lastName, $email, $phone, $address, $city) {
    // ...
}

// ✅ GOOD: Use a parameter object (DTO)
public function createUser(UserData $userData) {
    // ...
}
```

### Type Safety and Documentation

#### Strict Typing

**Rule**: All PHP files MUST declare `declare(strict_types=1);` at the top of the file.

**Enforcement**: PHP-CS-Fixer and PHPStan can enforce this rule.

#### Type Hints

**Rule**: All method parameters and return types MUST have explicit type hints.

**Enforcement**:
- PHPStan Level 7+ enforces strict type checking.
- Missing type hints will be flagged by static analysis.

**Example:**
```php
// ❌ BAD: Missing type hints
public function processData($data) {
    return $result;
}

// ✅ GOOD: Explicit type hints
public function processData(array $data): array {
    return $result;
}
```

#### Property Type Hints

**Rule**: All class properties MUST have explicit type hints.

**Enforcement**:
- PHPStan Level 7+ enforces property type hints.
- Missing property type hints will be flagged by static analysis.

**Example:**
```php
// ❌ BAD: Missing property type hint
class MyClass {
    private $value;
}

// ✅ GOOD: Explicit property type hint
class MyClass {
    private string $value;
}
```

#### DocBlocks

**Rule**: DocBlocks are required for:
- Public and protected methods (especially those part of the public API)
- Complex methods where the type hint alone doesn't fully explain the behavior
- Methods that throw exceptions

**Enforcement**: Code review and PHPStan can help identify missing DocBlocks.

**Example:**
```php
// ✅ GOOD: DocBlock for complex method
/**
 * Processes user data and returns validation results.
 *
 * @param array<string, mixed> $userData The user data to process
 * @return array{valid: bool, errors: array<string>} Validation results
 * @throws \InvalidArgumentException When user data is malformed
 */
protected function processUserData(array $userData): array {
    // ...
}
```

## Testing & Assertions

### Goal: 100% Test Coverage

**Critical**: 100% test coverage is prioritized over architectural purity. Every line of code that can be tested must be tested. Use `@codeCoverageIgnore` annotations only for truly untestable code paths (e.g., PHAR-specific code, edge cases that cannot be simulated).

**Important**: When using `@codeCoverageIgnore`,  `@codeCoverageIgnoreStart` and `@codeCoverageIgnoreEnd`, these annotations must be on their own lines. If you need to add an explanatory comment, place it on a separate line before the ignore tag:

```php
// Exception from rename() is extremely rare and hard to simulate
// @codeCoverageIgnoreStart
try {
    rename($binaryPath, $backupPath);
} catch (\Exception $e) {
    // ...
}
// @codeCoverageIgnoreEnd
```

**Do NOT** combine the comment with the annotation on the same line:
```php
// ❌ BAD: Comment and annotation on same line
// @codeCoverageIgnoreStart - Exception from rename() is extremely rare
```

### Dependency Isolation in Unit Tests

**Critical Rule**: The use of real service instances (Handlers, Providers, Repositories) in unit tests is **forbidden**. All service dependencies MUST be mocked.

**Why this matters:**
- Unit tests should test a single unit of code in isolation.
- Real service instances can introduce side effects, network calls, file system operations, etc.
- Mocking ensures tests are fast, predictable, and isolated.
- Mocking allows you to control the behavior of dependencies and test edge cases.

**DO NOT use real service instances:**
```php
// ❌ BAD: Using real service instances
public function testHandler() {
    $gitRepository = new GitRepository(); // Real instance
    $jiraService = new JiraService(); // Real instance
    $handler = new UpdateHandler($gitRepository, $jiraService);
    // This test may make real API calls or modify the file system!
}

// ✅ GOOD: Using mocks
public function testHandler() {
    $gitRepository = $this->createMock(GitRepository::class);
    $jiraService = $this->createMock(JiraService::class);
    $handler = new UpdateHandler($gitRepository, $jiraService);
    // This test is isolated and predictable
}
```

**What to mock:**
- Handler classes (e.g., `UpdateHandler`, `CommitHandler`)
- Service classes (e.g., `GitRepository`, `JiraService`, `GithubProvider`)
- Any class that is injected via dependency injection

**What NOT to mock (acceptable to use real instances):**
- Simple value objects (DTOs, Enums)
- Standard library classes (e.g., `DateTime`, `stdClass`)
- Simple utility classes with no external dependencies

### Core Principle: "Test the Intent, Not the Text"

This is a fundamental principle that guides all test writing in `stud-cli`.

**DO NOT assert on specific output strings** like:
```php
// ❌ BAD: Testing implementation details
$this->assertStringContainsString('File not found', $outputText);
$this->assertStringContainsString('Update complete! You are now on v1.0.1', $outputText);
```

**DO assert on:**
1. **Mocked IO calls** - Verify that the correct methods were called with expected parameters:
   ```php
   // ✅ GOOD: Testing behavior, not text
   $io->expects($this->once())
       ->method('error')
       ->with($this->callback(function ($messages) {
           return is_array($messages) && count($messages) > 0;
       }));
   ```

2. **Function return values** - Test the actual behavior and outcomes:
   ```php
   // ✅ GOOD: Testing the result
   $result = $handler->handle($io);
   $this->assertSame(1, $result); // Error case returns 1
   $this->assertSame(0, $result); // Success case returns 0
   ```

3. **Thrown exceptions** - Verify that exceptions are thrown when expected:
   ```php
   // ✅ GOOD: Testing exception behavior
   $this->expectException(\RuntimeException::class);
   $this->expectExceptionMessage('Invalid configuration');
   $handler->handle($io);
   ```

**Why this matters:**
- Tests become resilient to refactoring (changing error messages doesn't break tests)
- Tests focus on behavior and outcomes, not implementation details
- Tests remain readable and maintainable
- Tests verify that the code does the right thing, not just that it outputs specific text

## Command Output Conventions

The following table defines the standard output methods and their usage in `stud-cli`:

| Type | Method | Icon | Usage |
|------|--------|------|-------|
| **Success** | `$io->success()` | ✅ | Use when an operation completes successfully. Example: "✅ Branch 'feature/TPW-123' created from 'origin/develop'." |
| **Error** | `$io->error()` | ❌ | Use when an operation fails or encounters an error. Can accept a string or array of strings. Example: `$io->error(['Failed to create branch.', 'Error: ' . $e->getMessage()])` |
| **Warning** | `$io->warning()` | ⚠️ | Use to warn about potential issues or non-critical problems. Example: "⚠️ No Git provider configured for this project." |
| **Notice** | `$io->note()` | ℹ️ | Use for informational notices that are not errors or warnings. Example: "ℹ️ A Pull Request already exists for this branch." |
| **Info** | `$io->text()` | (none) | Use for general informational text that doesn't require special formatting. Example: "Fetching latest changes from origin..." |
| **Section** | `$io->section()` | (none) | Use to create a section header for grouping related output. Example: `$io->section('Checking for updates')` |

### Output Best Practices

1. **Be consistent**: Use the same output method for similar situations across the codebase.
2. **Be concise**: Error messages should be clear and actionable.
3. **Use arrays for multi-line messages**: When providing detailed error information, use arrays:
   ```php
   $io->error([
       'Failed to download the new version.',
       'Error: ' . $e->getMessage(),
   ]);
   ```
4. **Respect verbosity**: Use `$io->isVerbose()` to provide additional details when the `-v` flag is used:
   ```php
   if ($io->isVerbose()) {
       $io->writeln("  <fg=gray>JQL Query: {$jql}</>");
   }
   ```

## CHANGELOG.md Format

The `CHANGELOG.md` file follows the [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) format with the following sections:

- **`### Added`**: For new features and additions
- **`### Changed`**: For updates and modifications to existing functionality
- **`### Fixed`**: For bug fixes
- **`### Breaking`**: For breaking changes that require user action

**Important**: Breaking changes must be placed in the `### Breaking` section. Do not use markers like `[BREAKING CHANGE]`, `[BREAKING]`, or `[REMOVED]` within other sections. Breaking changes should be clearly separated into their own section.

## Static Analysis Tools

### PHP-CS-Fixer

**Purpose**: Automatically enforces PSR-12 code style and consistent formatting.

**Configuration**: `.php-cs-fixer.dist.php`

**Usage**:
```bash
# Check for style violations
vendor/bin/php-cs-fixer fix --dry-run --diff

# Fix style violations automatically
vendor/bin/php-cs-fixer fix
```

**Enforcement**: Run PHP-CS-Fixer before committing code. The tool will automatically correct style violations according to PSR-12 standards.

### PHPStan

**Purpose**: Performs static analysis to catch type errors, identify complex code structures, and enforce type safety.

**Configuration**: `phpstan.neon.dist` (configured to Level 7 minimum)

**Usage**:
```bash
# Run static analysis
vendor/bin/phpstan analyse
```

**Enforcement**: PHPStan must pass at Level 7 or higher before code can be merged. The tool helps identify:
- Type errors and missing type hints
- Complex code structures that may violate complexity thresholds
- Potential bugs and code smells

**AI Agent Tooling**: During Phase 1 (Planning) and Phase 3 (Integrity), the AI Agent must use PHPStan and other static analysis techniques to calculate and enforce all quality metrics listed in the Project Quality Metric Blueprint.

## Summary

- **Code Architecture**: Follow PSR-12 and SOLID principles. Use `protected` for testable helper methods, avoid `final` on injectable services.
- **Code Quality**: All code must adhere to the Project Quality Metric Blueprint:
  - **Complexity**: CC ≤ 10, CRAP Index ≤ 10, NPath ≤ 200, Nesting Depth ≤ 3
  - **Cohesion**: LCOM4 ≤ 2
  - **Size**: Class ≤ 400 lines, Method ≤ 40 lines
  - **Signatures**: Class Properties ≤ 10, Method Arguments ≤ 4
- **Type Safety**: All files must use `declare(strict_types=1);`. All methods and properties must have explicit type hints. DocBlocks required for public/protected methods and complex logic.
- **Testing**: Aim for 100% coverage. Test the intent (behavior, return values, exceptions) rather than specific output text. All service dependencies must be mocked in unit tests.
- **Static Analysis**: PHP-CS-Fixer enforces PSR-12 style. PHPStan (Level 7+) enforces type safety and helps identify complexity violations.
- **Output**: Use the standardized output methods consistently to provide a uniform user experience.
- **CHANGELOG**: Use `### Breaking` section for breaking changes, not inline markers.

These conventions ensure that `stud-cli` remains maintainable, testable, and provides a consistent developer experience.

