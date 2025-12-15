# stud-cli AI Development Protocol

## 1. Objective

Your objective is to automate the maintenance and feature development of the `stud-cli` application. You will be provided with a Jira work item (issue) key, and you are expected to deliver a complete, tested, and documented feature, submitted as a pull request on GitHub.

## 2. Core Directives & Constraints

-   **Standards Compliance**: You MUST read, understand, and adhere to 100% of the rules defined in the `CONVENTIONS.md` file. This is a blocking requirement. All code will be rejected if it violates these standards.
-   **Workflow Enforcement**: All Jira and Git operations MUST be performed via the `stud-cli` binary. Direct use of `git` or other VCS commands is forbidden. Refer to the `README.md` for a full command reference.
-   **API Immutability**: You MUST NOT modify, alter, or update any Jira or GitHub API endpoints, authentication logic, or credentials. This is a critical system constraint.
-   **Idempotency**: Prefer default values when prompted by the `stud-cli` tool during the commit process, unless the Jira ticket explicitly requires a different value.

## 3. Four-Phase Development Protocol

The development process is structured into four distinct phases, each with specific deliverables and quality gates. You **MUST** complete all phases in order before proceeding to the next and for the task you are given by the user.
You must always prefer stud cli commands over equivalent git manual commands. Only fallback to git manual commands if Stud-Cli does not provide one.

### Phase 1: Investigate and Plan

**Objective**: Thoroughly understand the requirements and create a formal, executable plan before writing any code.

**Steps**:

1.  **Ingest & Verify**: Use `stud sh <JiraWorkItemKey>` to verify the ticket exists and to understand its requirements. If the ticket cannot be found, halt the process and report an error. You must carefully read the ticket's description, acceptance criteria, and any linked documentation.

2.  **Branch Management**: Check which branch you are currently on using `git branch --show-current`. If you are not on a feature branch for this ticket, use `stud start <JiraWorkItemKey>` to create the feature branch. If you already are on the right branch, proceed to the next step.

3.  **Codebase Analysis**: 
    - Search the codebase to understand existing patterns, similar features, and relevant code.
    - Identify all files that will be modified or created.
    - Check for any existing tests related to the functionality.

4.  **Complexity Assessment**: For each file that will be modified or created, assess the complexity:
    - Check if any methods exceed Cyclomatic Complexity (CC) of 10.
    - Check if any classes exceed CRAP Index of 10.
    - **If violations are found**: You MUST add a refactoring task to your plan to fix the legacy code BEFORE implementing the new feature. This is a blocking requirement.

5.  **Plan Creation**: Create a formal, structured plan that:
    - Lists all files to be created or modified.
    - Describes the implementation approach for each component.
    - Identifies test cases that need to be written or updated.
    - Follows the KISS (Keep It Simple, Stupid) principle: prefer simple, straightforward solutions over complex ones.
    - **Self-Review**: Before proceeding, critically review your plan:
        - Is the approach the simplest possible solution?
        - Are there any unnecessary abstractions or over-engineering?
        - Can any steps be simplified or combined?
        - Does the plan address all acceptance criteria from the ticket?

6.  **Plan Validation**: The plan must be complete and executable. If the plan is incomplete or unclear, refine it before proceeding to Phase 2.

**Deliverable**: A clear, structured plan that addresses all ticket requirements and includes any necessary refactoring tasks for complexity violations.

### Phase 2: Execution and Documentation

**Objective**: Implement the feature according to the plan, write tests, and update documentation.

**Steps**:

1.  **Implementation**: 
    - Implement the required feature according to the plan created in Phase 1.
    - Follow all conventions defined in `CONVENTIONS.md`.
    - Ensure all new code adheres to complexity thresholds (CC ≤ 10, CRAP Index ≤ 10).
    - If refactoring was required in Phase 1, complete it first before implementing new features.

2.  **Test Development**: 
    - Create or update PHPUnit tests to ensure the new functionality is covered.
    - Follow the "Test the Intent, Not the Text" principle from `CONVENTIONS.md`.
    - Ensure all tests use mocks for service dependencies (Handlers, Providers, Repositories); real service instances are forbidden in unit tests.
    - All tests must pass before proceeding.

3.  **Documentation Updates**:
    - Update the `README.md` if the feature introduces a new command or changes existing command behavior.
    - Add a new entry to `CHANGELOG.md` *under the existing* `## [Unreleased]` header. You **MUST NOT** create new version headers; the `stud release` command handles this.

**Deliverable**: Complete implementation with passing tests and updated documentation.

### Phase 3: Project Integrity and Commit

**Objective**: Ensure the entire project maintains quality standards and commit the changes.

**Steps**:

1.  **Code Coverage Verification**: 
    - **CRITICAL**: Run the full test suite and verify that the entire project maintains 100% code coverage (not just new files).
    - If coverage drops below 100%, you MUST write additional tests to restore full coverage before proceeding.
    - This is a blocking requirement; the commit cannot proceed until 100% coverage is restored.

2.  **Complexity Verification**: 
    - Verify that all modified and new code adheres to complexity thresholds:
        - All methods have Cyclomatic Complexity ≤ 10.
        - All classes have CRAP Index ≤ 10.
    - If violations are found, refactor the code to meet the thresholds.

3.  **Standards Compliance Check**: 
    - Review all changes against `CONVENTIONS.md` to ensure compliance:
        - Visibility modifiers are correct (protected for testable methods).
        - No `final` keyword on injectable services.
        - Tests follow "Test the Intent, Not the Text" principle.
        - All service dependencies in tests are mocked.

4.  **Review**: Review your changes and compare them with the ticket's description to ensure your changes cover all requirements and acceptance criteria.

5.  **Commit**: Use `stud commit` to generate the commit message. Only commit if there are meaningful, relevant changes.

**Deliverable**: All changes committed with a proper conventional commit message, and the entire project maintains 100% code coverage and meets all quality thresholds.

### Phase 4: Summarize and Conclude

**Objective**: Generate a comprehensive report and submit the work as a pull request.

**Steps**:

1.  **Report Generation**: Create a detailed report that includes:
    - A summary of all changes made.
    - A TODO list approach with OK / KO status for each requirement from the ticket:
        - ✅ OK: Requirement met as specified.
        - ❌ KO: Requirement not met (with explanation).
    - A list of any assumptions made or deviations from the ticket requirements, with explanations for why they were necessary.
    - Any additional improvements or refactoring that was done beyond the ticket scope.

2.  **Submit Pull Request**: Use `stud submit --labels "AI-Generated,RFR"` to create the pull request. This step MUST only be performed after all previous phases, including testing, documentation, and integrity checks, are complete.

3.  **PR Comment**: After submitting the PR (Step 2), pipe your report directly to the PR as a comment using `stud pr:comment` (or `stud pc`). For example: `echo "Your report content here" | stud pr:comment`

4.  **Iterate**: Ask the user if they want more work to be done on the ticket OR if you should work on another task.

**Deliverable**: Pull request submitted with a comprehensive report posted as a comment.

## 4. Technical Context

-   **Language**: PHP (use `php -v` to determine which version is installed)
-   **Framework**: `jolicode/castor` is used for task running.
-   **Build**: `repack` is used for PHAR compilation (the command to build the application is `PATH="~/.config/composer/vendor/bin:$PATH" vendor/bin/castor repack --logo-file src/repack/logo.php --app-name stud --app-version 1.0.0 && mv stud.linux.phar stud.phar`).
-   **Dependencies**: See `composer.json` for a full list of dependencies.
-   **Debugging**: When implementing new features, add contextual debug logs accessible via the `-v`, `-vv`, and `-vvv` verbosity flags.

### Commit Best Practices for Gemini

*   **Meaningful Changes Only:** When instructed to commit changes (e.g., via `stud commit`), ensure that the codebase has undergone actual, meaningful modifications relevant to the task.
*   **Avoid Artificial Commits:** Never introduce artificial or 'placeholder' changes (such as empty comments, whitespace adjustments, or non-functional code) solely to create a commit for testing purposes.
*   **Handle No Changes Gracefully:** If a `stud commit` operation is requested but no relevant changes have been made to the working directory, inform the user of this state and ask for clarification on how to proceed, rather than attempting to force a commit with irrelevant modifications.
