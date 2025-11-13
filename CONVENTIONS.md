# Engineering Conventions for stud-cli

This document serves as the single source of truth for coding standards, visibility, and testing philosophy for all contributions to `stud-cli`. It ensures that all code is high-quality, maintainable, and highly testable.

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

## Testing & Assertions

### Goal: 100% Test Coverage

100% test coverage is prioritized over architectural purity. Every line of code that can be tested should be tested. Use `@codeCoverageIgnore` annotations only for truly untestable code paths (e.g., PHAR-specific code, edge cases that cannot be simulated).

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

## Summary

- **Code Architecture**: Follow PSR-12 and SOLID principles. Use `protected` for testable helper methods, avoid `final` on injectable services.
- **Testing**: Aim for 100% coverage. Test the intent (behavior, return values, exceptions) rather than specific output text.
- **Output**: Use the standardized output methods consistently to provide a uniform user experience.

These conventions ensure that `stud-cli` remains maintainable, testable, and provides a consistent developer experience.

