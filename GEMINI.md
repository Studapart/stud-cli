# stud-cli AI Development Protocol

## 1. Objective

Your objective is to automate the maintenance and feature development of the `stud-cli` application. You will be provided with a Jira issue key, and you are expected to deliver a complete, tested, and documented feature, submitted as a pull request on GitHub.

## 2. Core Directives & Constraints

-   **Workflow Enforcement**: All Jira and Git operations MUST be performed via the `stud-cli` binary. Direct use of `git` or other VCS commands is forbidden. Refer to the `README.md` for a full command reference.
-   **API Immutability**: You MUST NOT modify, alter, or update any Jira or GitHub API endpoints, authentication logic, or credentials. This is a critical system constraint.
-   **Standards Compliance**: All code produced MUST adhere to PSR-12, SOLID principles, and strict typing.
-   **Idempotency**: Prefer default values when prompted by the `stud-cli` tool during the commit process, unless the Jira ticket explicitly requires a different value.

## 3. Procedure you MUST follow
For your task, you MUST follow these steps:

1.  **Ingest & Verify**: Use `stud sh <JiraWorkItemKey>` to verify the ticket exists and to understand its requirements. If the ticket cannot be found, halt the process and report an error. You must carefully read the ticket's description.
2.  **Branch**: Use `stud start <JiraWorkItemKey>` to create the feature branch.
3.  **Develop**: Implement the required feature. This includes writing new code and updating existing code as necessary.
4.  **Test**: Create or update PHPUnit tests to ensure the new functionality is covered and that all tests pass.
5.  **Document**:
    -   Update the `README.md` if the feature introduces a new command or changes existing command behavior.
    -   IF there is no `CHANGELOG.md` file, create one following the Keep a Changelog format.
    -   IF there is a `CHANGELOG.md` file, update it with the new entry following the Keep a Changelog format.
6.  **Commit**: Use `stud commit` to generate the commit message.
7.  **Submit**: Use `stud submit` to create the pull request. This step MUST only be performed after all previous steps, including testing and documentation, are complete.

## 4. Technical Context

-   **Language**: PHP (use `php -v` to determine which version is installed)
-   **Framework**: `jolicode/castor` is used for task running.
-   **Build**: `repack` is used for PHAR compilation (the command to build the application is `PATH="~/.config/composer/vendor/bin:$PATH" vendor/bin/castor repack --logo-file repack/logo.php stud.phar`).
-   **Dependencies**: See `composer.json` for a full list of dependencies.
-   **Debugging**: When implementing new features, add contextual debug logs accessible via the `-v`, `-vv`, and `-vvv` verbosity flags.

### Commit Best Practices for Gemini

*   **Meaningful Changes Only:** When instructed to commit changes (e.g., via `stud commit`), ensure that the codebase has undergone actual, meaningful modifications relevant to the task.
*   **Avoid Artificial Commits:** Never introduce artificial or 'placeholder' changes (such as empty comments, whitespace adjustments, or non-functional code) solely to create a commit for testing purposes.
*   **Handle No Changes Gracefully:** If a `stud commit` operation is requested but no relevant changes have been made to the working directory, inform the user of this state and ask for clarification on how to proceed, rather than attempting to force a commit with irrelevant modifications.