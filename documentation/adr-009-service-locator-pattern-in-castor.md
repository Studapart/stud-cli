# [ADR-009] Service Locator Pattern in castor.php

* **Status:** `Accepted`
* **Date:** 2026-02-02
* **Authors:** Engineering Team
* **Deciders:** Head of Engineering
* **Technical Context:** PHP 8.2+, Castor Framework, Symfony 7.3

## 1. Context and Problem Statement

**The Pain Point:** Castor framework uses function-based task definitions rather than class-based controllers. This creates challenges for dependency injection:
- **No DI Container:** Castor doesn't provide a Symfony-style DI container
- **Function Scope:** Task functions can't use constructor injection
- **Service Creation:** Need a way to create and share services across tasks
- **Testability:** Services need to be mockable for tests
- **Configuration:** Services need access to configuration and other services

**The Goal:** Establish a service location pattern that:
- Provides services to task functions
- Supports dependency injection
- Enables testability through service replacement
- Maintains service lifecycle (singleton vs. new instances)
- Works with Castor's function-based architecture

## 2. Decision Drivers & Constraints

* **Castor Framework:** Must work with function-based task definitions
* **Dependency Injection:** Services need dependencies injected
* **Testability:** Services must be mockable for tests
* **Performance:** Services should be created efficiently (singletons where appropriate)
* **Configuration:** Services need access to configuration
* **Maintainability:** Pattern should be clear and consistent

## 3. Considered Options

* **Option 1:** Create services directly in task functions
  * Pros: Simple, explicit
  * Cons: Code duplication, hard to test, no dependency injection

* **Option 2:** Use global variables for services
  * Pros: Simple, accessible
  * Cons: Not testable, no lifecycle control, global state issues

* **Option 3:** Service Locator Pattern with helper functions (Chosen)
  * Pros: Centralized, testable, supports DI, clear pattern
  * Cons: Not "pure" DI, but pragmatic for Castor

* **Option 4:** Create a lightweight DI container
  * Pros: More "proper" DI
  * Cons: Over-engineered for Castor, adds complexity

## 4. Decision Outcome

**Chosen Option:** `Option 3 - Service Locator Pattern with Helper Functions`

**Justification:**
We chose the service locator pattern because:
1. **Pragmatic:** Works with Castor's function-based architecture
2. **Testable:** Services can be replaced via `TestKernel` for tests
3. **Centralized:** All service creation in one place (`castor.php`)
4. **Dependency Injection:** Services can depend on other services
5. **Lifecycle Control:** Supports both singletons and new instances
6. **Configuration Access:** Services can access configuration through helper functions

The pattern uses:
- **Helper Functions:** `_get_jira_service()`, `_get_git_repository()`, etc.
- **TestKernel:** Allows test replacement of services
- **Static Caching:** Services are cached where appropriate (singletons)
- **Dependency Chain:** Services can call other service helpers

## 5. Consequences (Trade-offs)

| Aspect | Result (Positive / Negative / Neutral) |
| --- | --- |
| **Testability** | *(+) Services can be replaced via TestKernel for tests* |
| **Centralization** | *(+) All service creation in one place, easy to find* |
| **Dependency Injection** | *(+) Services can depend on other services* |
| **Lifecycle Control** | *(+) Supports singletons and new instances* |
| **OOP Purity** | *(-) Not "pure" dependency injection, but pragmatic* |
| **Maintainability** | *(+) Clear pattern, easy to understand* |
| **Performance** | *(+) Static caching for singletons, efficient* |

## 6. Implementation Plan

* [x] Create helper functions for each service (`_get_jira_service()`, `_get_git_repository()`, etc.)
* [x] Implement static caching for singleton services
* [x] Create `TestKernel` class for test service replacement
* [x] Document service creation pattern
* [x] Ensure services can depend on other services
* [x] Update all task functions to use helper functions

---

### Implementation Details

**Service Helper Functions:**

```php
function _get_jira_service(): JiraService
{
    // Check for test replacement
    if (class_exists("\App\Tests\TestKernel::class") && \App\Tests\TestKernel::$jiraService) {
        return \App\Tests\TestKernel::$jiraService;
    }

    // Create real service with dependencies
    $config = _get_jira_config();
    $client = HttpClient::createForBaseUri($config['JIRA_URL'], [...]);
    return new JiraService($client, _get_html_converter());
}

function _get_git_repository(): GitRepository
{
    // Check for test replacement
    if (class_exists("\App\Tests\TestKernel") && \App\Tests\TestKernel::$gitRepository) {
        return \App\Tests\TestKernel::$gitRepository;
    }

    // Create real service with dependencies
    return new GitRepository(_get_process_factory(), _get_file_system());
}
```

**Singleton Pattern:**

```php
function _get_file_system(): FileSystem
{
    static $fileSystem = null;
    if ($fileSystem === null) {
        $fileSystem = FileSystem::createLocal();
    }
    return $fileSystem;
}
```

**Test Replacement:**

```php
// In tests
class TestKernel
{
    public static ?JiraService $jiraService = null;
    public static ?GitRepository $gitRepository = null;
    // ...
}

// In test setup
TestKernel::$jiraService = $this->createMock(JiraService::class);
```

**Usage in Tasks:**

```php
#[AsTask(name: 'items:list', ...)]
function items_list(...): void
{
    _load_constants();
    $handler = new ItemListHandler(_get_jira_service());
    // ...
}
```

**Dependency Chain:**

```php
function _get_git_provider(): ?GitProviderInterface
{
    $gitRepository = _get_git_repository(); // Depends on GitRepository
    $logger = _get_logger(); // Depends on Logger
    $translator = _get_translation_service(); // Depends on TranslationService
    // ...
}
```

---

### Pro-Tip for Symfony 7.4 ADRs

This decision is a **pragmatic compromise** between pure dependency injection and Castor's function-based architecture. While Symfony 7.4 favors **Autowiring and Service Container**, Castor's function-based tasks require a different approach. The service locator pattern provides the benefits of DI (testability, dependency management) while working within Castor's constraints. This aligns with Symfony 7.4's principle of **Pragmatism over Purity** - choosing the right tool for the job.
