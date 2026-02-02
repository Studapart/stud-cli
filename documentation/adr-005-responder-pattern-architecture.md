# [ADR-005] Responder Pattern (Action-Domain-Responder) Architecture

* **Status:** `Accepted`
* **Date:** 2026-02-02
* **Authors:** Engineering Team
* **Deciders:** Head of Engineering
* **Technical Context:** PHP 8.2+, Symfony 7.3, Castor Framework

## 1. Context and Problem Statement

**The Pain Point:** Initial implementation mixed business logic with presentation logic in Handler classes. Handlers were directly writing to console output, making them:
- **Hard to Test:** Required mocking `SymfonyStyle` and asserting on output strings
- **Tightly Coupled:** Business logic was coupled to console presentation
- **Inflexible:** Couldn't easily change output format or add new presentation layers
- **Violated SRP:** Handlers were responsible for both domain logic and presentation

**The Goal:** Separate business logic from presentation logic to improve testability, maintainability, and flexibility. Enable handlers to be tested in isolation without console I/O dependencies.

## 2. Decision Drivers & Constraints

* **Testability:** Handlers should be testable without mocking console I/O
* **Separation of Concerns:** Business logic should be independent of presentation
* **SOLID Principles:** Follow Single Responsibility Principle (SRP)
* **Symfony Best Practices:** Use DTOs and service composition
* **Maintainability:** Easy to modify presentation without changing business logic
* **Extensibility:** Easy to add new view types or output formats

## 3. Considered Options

* **Option 1:** Keep handlers with direct I/O (Original)
  * Pros: Simple, fewer classes
  * Cons: Hard to test, tightly coupled, violates SRP

* **Option 2:** Use Responder Pattern (ADR - Action Domain Responder) (Chosen)
  * Pros: Clear separation, highly testable, follows SOLID, extensible
  * Cons: More classes, requires understanding of pattern

* **Option 3:** Use Strategy Pattern for output
  * Pros: Flexible output strategies
  * Cons: More complex, doesn't solve testability issue

* **Option 4:** Use Event-Driven Architecture
  * Pros: Decoupled, extensible
  * Cons: Over-engineered for CLI tool, adds complexity

## 4. Decision Outcome

**Chosen Option:** `Option 2 - Responder Pattern (ADR)`

**Justification:**
We chose the Responder pattern because:
1. **Clear Separation:** Action (castor.php) → Domain (Handler) → Responder (Presentation)
2. **Testability:** Handlers return pure data (Response DTOs), no I/O dependencies
3. **SOLID Compliance:** Each layer has a single responsibility
4. **Extensibility:** New view types can be added without modifying handlers
5. **Reusability:** ViewConfigs can be reused across different handlers
6. **Industry Standard:** ADR pattern is well-documented and understood

The architecture consists of:
- **Action (Task):** Orchestrates in `castor.php`, wires Handler → Responder
- **Domain (Handler):** Pure business logic, returns `Response` DTOs
- **Responder:** Presentation logic, renders `Response` objects to console

## 5. Consequences (Trade-offs)

| Aspect | Result (Positive / Negative / Neutral) |
| --- | --- |
| **Testability** | *(+) Handlers can be tested without I/O mocking, tests focus on data transformation* |
| **Maintainability** | *(+) Clear separation makes code easier to understand and modify* |
| **Code Organization** | *(+) Logical structure: Handlers for logic, Responders for presentation* |
| **Complexity** | *(-) More classes and files, requires understanding of pattern* |
| **Performance** | *(Neutral) Minimal overhead, DTOs are lightweight* |
| **Flexibility** | *(+) Easy to add new view types or change presentation without touching handlers* |
| **Code Duplication** | *(Neutral) Some duplication in Responder setup, but ViewConfigs reduce it* |

## 6. Implementation Plan

* [x] Create `Response` DTO classes extending `AbstractResponse`
* [x] Create `ResponseInterface` for type safety
* [x] Refactor handlers to return Response objects instead of handling I/O
* [x] Create `Responder` classes for each handler type
* [x] Create `ViewConfig` infrastructure (`TableViewConfig`, `PageViewConfig`)
* [x] Create supporting value objects (`Column`, `DefinitionItem`, `Section`, `Content`)
* [x] Update `castor.php` task functions to orchestrate Handler → Responder flow
* [x] Refactor tests to test pure domain logic (no I/O mocking)
* [x] Create comprehensive responder tests

---

### Implementation Details

**Architecture Layers:**

1. **Action Layer (`castor.php`):**
   ```php
   #[AsTask(name: 'items:list', ...)]
   function items_list(...): void {
       $handler = new ItemListHandler($jiraService);
       $response = $handler->handle($all, $project, $sort);
       
       $errorResponder = _get_error_responder();
       if (!$response->isSuccess()) {
           $errorResponder->respond(io(), $response);
           exit(1);
       }
       
       $responder = new ItemListResponder($translator, $colorHelper);
       $responder->respond(io(), $response);
   }
   ```

2. **Domain Layer (Handlers):**
   ```php
   class ItemListHandler {
       public function handle(bool $all, ?string $project, ?string $sort): ItemListResponse {
           // Pure business logic, no I/O
           $items = $this->jiraService->getItems($all, $project, $sort);
           return ItemListResponse::success($items);
       }
   }
   ```

3. **Presentation Layer (Responders):**
   ```php
   class ItemListResponder {
       public function respond(SymfonyStyle $io, ItemListResponse $response): void {
           $config = TableViewConfig::fromResponse($response, [
               Column::create('key', 'table.key'),
               Column::create('status', 'table.status'),
               Column::create('summary', 'table.summary'),
           ]);
           $this->renderTable($io, $config);
       }
   }
   ```

**Response DTOs:**
- All Response classes extend `AbstractResponse`
- Use static factory methods: `Response::success($data)` and `Response::error($message)`
- Implement `ResponseInterface` for type safety

**ViewConfig Infrastructure:**
- `TableViewConfig`: For tabular data with conditional columns
- `PageViewConfig`: For page-style output with sections and definition lists
- Value objects: `Column`, `DefinitionItem`, `Section`, `Content`

**Exception:**
- `StatusHandler` does not follow Responder pattern (intentionally)
- It's a simple dashboard (~60 lines) with unique format
- Refactoring would add complexity without benefit

---

### Pro-Tip for Symfony 7.4 ADRs

This decision follows **Composition over Inheritance** principles. Handlers compose services (JiraService, GitRepository) rather than extending base classes. Responders compose ViewConfigs rather than inheriting presentation logic. This aligns with Symfony 7.4's preference for composition and dependency injection.
