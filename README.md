<div align="center">
  <img src="src/resources/logo-300.png" alt="Stud-Cli Logo" width="300">
</div>

# stud-cli: Jira & Git Workflow Streamliner

`stud-cli` is a command-line interface tool designed to streamline a developer's daily workflow by tightly integrating Jira work items with local Git repository operations. It guides you through the "golden path" of starting a task, making conventional commits, and preparing your work for submission, all from the command line.

## Table of Contents

- [System Requirements](#system-requirements)
- [For Developers](#for-developers)
  - [Project Goal](#project-goal)
  - [Architectural Principles](#architectural-principles)
  - [Developer Setup](#developer-setup)
  - [Compiling to PHAR (using Box)](#compiling-to-phar-using-box)
  - [Developer Troubleshooting](#developer-troubleshooting)
- [For Users](#for-users)
  - [User Installation](#user-installation)
  - [Configuration](#configuration)
  - [Usage](#usage)
    - [Configuration Commands](#configuration-commands)
    - [Jira Information Commands](#jira-information-commands)
    - [Git Workflow Commands](#git-workflow-commands)
  - [User Troubleshooting](#user-troubleshooting)

---

## System Requirements

### PHP Version

- **PHP 8.2 or higher** is required.

### PHP Extensions

The following PHP extensions are required:

- **php-xml**: Required for HTML to Markdown conversion (used in `stud submit` command)

#### Installation

**Ubuntu/Debian:**
```bash
sudo apt-get install php-xml
```

**Fedora/RHEL:**
```bash
sudo dnf install php-xml
```

**macOS (Homebrew):**
```bash
brew install php-xml
```

#### Verification

To verify that the XML extension is installed, run:
```bash
php -m | grep xml
```

If the command returns nothing, the extension is not installed. Install it using your system's package manager (see instructions above).

---

## For Developers

This section is for developers who want to contribute to `stud-cli`, run it directly from source, or understand its internal workings.

### Project Goal

The purpose of `stud-cli` is to streamline a developer's daily workflow by tightly integrating their Jira work items with their local Git repository. It will guide them through the "golden path" of starting a task, making conventional commits, and preparing their work for submission, all from the command line.

### Architectural Principles

-   **Jira v1 Scope (Read-Only):** The tool *only* reads data from Jira. It does not modify tickets, add comments, or change issue statuses. Server-side state changes are expected to be handled by Jira-GitHub connector webhooks.
-   **Stateless (No Local Cache):** The tool does not use a local cache. The current Jira ticket key is parsed from the current Git branch name on every run, preventing stale data issues.
-   **Modern Git Practices:** Employs modern, unambiguous Git commands like `git switch -c`.
-   **User-Centric Defaults:** Commands like `items list` prioritize showing relevant information to the current user by default.
-   **Command Syntax:** Follows an `object:verb` pattern (e.g., `stud items:list`).
-   **Responder Pattern:** The application follows the Responder pattern (ADR - Action Domain Responder) to separate domain logic from presentation logic:
    - **Action (Task):** Orchestrates the use case in `castor.php`
    - **Domain (Handler):** Contains pure business logic and returns `Response` objects
    - **Responder:** Contains `ViewConfig` and renders `Response` objects to console output

#### Responder Pattern Architecture

The Responder pattern separates concerns between business logic (Handlers) and presentation logic (Responders):

**Response Classes** (`src/Response/`):
- Response classes are DTOs (Data Transfer Objects) that encapsulate the result of a Handler operation
- All Response classes extend `AbstractResponse` and implement `ResponseInterface`
- Response classes use static factory methods (`success()` and `error()`) for creation
- Available Response classes:
  - `FilterShowResponse`: For filter show operations
  - `ItemListResponse`: For item list operations
  - `ItemShowResponse`: For item show operations
  - `ProjectListResponse`: For project list operations
  - `SearchResponse`: For search operations

**Responder Classes** (`src/Responder/`):
- Responder classes handle all presentation logic and render Response objects to console output
- Responders use `ViewConfig` instances (typically `TableViewConfig` or `PageViewConfig`) to render data
- Responders handle error messages, empty states, section headers, and verbose output
- Available Responder classes:
  - `FilterShowResponder`: Renders filter show results with Priority and Jira URL columns
  - `ItemListResponder`: Renders item list results (Key, Status, Summary columns)
  - `ItemShowResponder`: Renders item show results with definition lists and formatted description sections
  - `ProjectListResponder`: Renders project list results (Key, Name columns)
  - `SearchResponder`: Renders search results with Priority and Jira URL columns

**ViewConfig Infrastructure** (`src/View/`):
- `ViewConfigInterface`: Defines the contract for rendering DTOs to console output
- `TableViewConfig`: Renders data in table format with support for:
  - Conditional column visibility (e.g., Priority column only shown when at least one item has a priority)
  - Column formatters (callables for value transformation)
- `PageViewConfig`: Renders data in page format with support for:
  - Sections with titles
  - Definition lists (key-value pairs)
  - Content blocks (text, listings, etc.)
- Supporting value objects:
  - `Column`: Defines table column structure (property, translation key, formatter, condition)
  - `DefinitionItem`: Defines definition list items (translation key, value extractor)
  - `Section`: Groups definition items and content blocks
  - `Content`: Defines content blocks with optional formatters

**Usage Example:**
```php
// In castor.php task function:
$handler = new FilterShowHandler($jiraService);
$response = $handler->handle($filterName);

$responder = new FilterShowResponder($translator, $jiraConfig);
exit($responder->respond($io, $response));

// Handler contains pure domain logic (no IO)
// Responder handles all presentation (sections, errors, tables)
```

**Note on StatusHandler:**
The `StatusHandler` is kept as-is and does not follow the Responder pattern. It's a simple dashboard view (~60 lines) that combines Jira status, Git branch, and local changes in a unique format. Refactoring it would add complexity without architectural benefit, as it doesn't fit the standard table/page pattern used by other handlers.

This architecture enables:
- Separation of concerns (business logic vs. presentation)
- Testability (Handlers return data, Responders handle output)
- Extensibility (new view types can be added without modifying Handlers)
- Reusability (ViewConfigs can be reused across different Handlers)

### Developer Setup

To get `stud-cli` running from source, the process is simple as all dependencies are managed by Composer.

1.  **Verify System Requirements:**
    Ensure you have PHP 8.2 or higher installed and the required PHP extensions (see [System Requirements](#system-requirements) above).

2.  **Verify PHP Extensions:**
    Ensure required PHP extensions are installed:
    ```bash
    php -m | grep xml
    ```
    If the command returns nothing, install the extension using your system's package manager (see [System Requirements](#system-requirements) above).

3.  **Clone the Repository:**
    ```bash
    git clone <your-repository-url>
    cd stud-cli
    ```

4.  **Install All Dependencies:**
    This single command will install all required PHP packages, including the Castor framework and the Box PHAR compiler, as they are defined in `composer.json`.
    ```bash
    composer install
    ```

5.  **Run the Tool:**
    You can now run `stud-cli` directly using the `stud` executable provided in the project root. This is a wrapper that invokes Castor.
    ```bash
    ./stud help
    ./stud config:init
    ```

### Compiling to PHAR (using Box)

This project is configured to be compiled into a single, executable PHAR file using [Box](https://box-project.github.io/). Installing `humbug/box` globally using `composer global require humbug/box`, then you can compile the application easily.

1.  **Ensure dependencies are installed:**
    If you haven't already, run `composer install`.

2.  **Compile:**
    ```bash
    PATH="~/.config/composer/vendor/bin:$PATH" vendor/bin/castor repack --logo-file src/repack/logo.php --app-name stud --app-version 1.0.0 && mv stud.linux.phar stud.phar
    ```
    This will generate an executable `stud.phar` file in the project's root directory.

3.  **Install (Recommended):**
    Move the compiled `stud.phar` to a user-owned directory in your PATH (e.g., `~/.local/bin/`):
    ```bash
    mv stud.phar ~/.local/bin/stud && chmod +x ~/.local/bin/stud
    ```
    Make sure `~/.local/bin/` is in your `$PATH`. This allows you to use `stud update` without needing `sudo`.

    **Alternative (Global installation):**
    If you prefer to install globally for all users:
    ```bash
    sudo mv stud.phar /usr/local/bin/stud && sudo chmod +x /usr/local/bin/stud
    ```
    Note: If you use this method, you will need to run `sudo stud update` to update the tool.

### Configuration Migration System

`stud-cli` includes a migration system that allows configuration format changes to be automatically applied when the tool is updated. This enables backward compatibility and smooth transitions when configuration schemas evolve.

#### Migration Types

**Global Migrations** (`src/Migrations/GlobalMigrations/`):
- Affect the global configuration file (`~/.config/stud/config.yml`)
- Run automatically during `stud update` (prerequisite migrations)
- Run on-demand when commands execute (non-prerequisite migrations)
- Use when configuration changes affect all users globally

**Project Migrations** (`src/Migrations/ProjectMigrations/`):
- Affect project-specific configuration (`.git/stud.config`)
- Run on-demand when commands execute in a git repository (lazy migrations)
- Use when configuration changes are project-specific

#### Creating a New Migration

1. **Choose the Migration Scope:**
   - Global migrations go in `src/Migrations/GlobalMigrations/`
   - Project migrations go in `src/Migrations/ProjectMigrations/`

2. **Create the Migration Class:**
   - Migration ID format: `YYYYMMDDHHIISS001` (timestamp + sequence number)
   - Example: `Migration202501160000001_MyFeature.php`
   - Extend `AbstractMigration` and implement required methods:
     ```php
     <?php
     declare(strict_types=1);
     
     namespace App\Migrations\GlobalMigrations; // or ProjectMigrations
     
     use App\Migrations\AbstractMigration;
     use App\Migrations\MigrationScope;
     
     class Migration202501160000001_MyFeature extends AbstractMigration
     {
         public function getId(): string
         {
             return '202501160000001';
         }
         
         public function getDescription(): string
         {
             return 'Description of what this migration does';
         }
         
         public function getScope(): MigrationScope
         {
             return MigrationScope::GLOBAL; // or PROJECT
         }
         
         public function isPrerequisite(): bool
         {
             return false; // true if must run during stud update
         }
         
         public function up(array $config): array
         {
             // Transform config from old format to new format
             $config['new_key'] = $config['old_key'] ?? 'default';
             unset($config['old_key']);
             return $config;
         }
         
         public function down(array $config): array
         {
             // Optional: Revert migration (for rollback)
             $config['old_key'] = $config['new_key'] ?? '';
             unset($config['new_key']);
             return $config;
         }
     }
     ```

3. **Migration Discovery:**
   - Migrations are automatically discovered by `MigrationRegistry`
   - They are sorted by ID and filtered based on the current `migration_version` in the config
   - Only pending migrations (ID > current version) are executed

4. **Migration Execution:**
   - Global migrations run during `stud update` (prerequisite) or via config pass listener
   - Project migrations run via config pass listener when in a git repository
   - Each migration updates the `migration_version` in the config after successful execution
   - Prerequisite migrations that fail prevent update completion
   - Non-prerequisite migrations that fail log errors but continue

#### Best Practices

- **Migration IDs:** Use timestamps to ensure proper ordering. Format: `YYYYMMDDHHIISS001`
- **Idempotency:** Migrations should be idempotent - running them multiple times should produce the same result
- **Backward Compatibility:** Old configs without `migration_version` are treated as version "0" and all migrations run
- **Error Handling:** Prerequisite migrations should be robust; non-prerequisite migrations can be more lenient
- **Testing:** Create test migrations in `tests/Migrations/Fixtures/` for testing the migration system

#### Example Use Cases

- **Format Changes:** Converting old config keys to new ones (e.g., `GIT_TOKEN` → `GITHUB_TOKEN`)
- **Schema Updates:** Adding new required configuration keys with defaults
- **Data Transformation:** Migrating data structures (e.g., array format changes)
- **Validation:** Adding validation rules or constraints to existing config

### Developer Troubleshooting

-   **`Configuration file not found`:** When running from source, ensure you've run `./stud config:init`.
-   **Command not found:** Ensure you are running the tool from the project root using `./stud`.

### Testing

This project uses PHPUnit for unit tests. To run the test suite, use the following command:

```bash
vendor/bin/phpunit
```

---

## For Users

This section is for users who want to use the `stud-cli` tool as a standalone executable (PHAR).

### User Installation

#### System Requirements

Before installing `stud-cli`, ensure you have:
- **PHP 8.2 or higher** installed
- **php-xml extension** installed (see [System Requirements](#system-requirements) above for installation instructions)

#### Recommended Installation (for seamless `stud update`)

This is the recommended installation method as it allows you to use `stud update` without needing `sudo`.

1.  **Download the `stud.phar` file:**
    Download the `stud.phar` file from the [Releases page](https://github.com/studapart/stud-cli/releases) on GitHub.

2.  **Move it to a user-owned binary directory:**
    Move the `stud.phar` file to a directory in your user's home directory that is in your shell's `$PATH`. Common locations include:
    - `~/.local/bin/` (standard on modern Linux/macOS)
    - `~/bin/` (custom directory)

    Example command:
    ```bash
    mv ./stud.phar ~/.local/bin/stud
    chmod +x ~/.local/bin/stud
    ```

3.  **Ensure the directory is in your PATH:**
    Make sure the directory you chose (e.g., `~/.local/bin/`) is in your shell's `$PATH`. You can verify this by running:
    ```bash
    echo $PATH
    ```
    
    If it's not in your PATH, add it to your shell configuration file (e.g., `~/.bashrc`, `~/.zshrc`):
    ```bash
    export PATH="$HOME/.local/bin:$PATH"
    ```

    Now you can run `stud-cli` commands from anywhere using `stud <command>`, and you'll be able to update the tool seamlessly with `stud update` without needing `sudo`.

#### Alternative Installation (Global sudo)

If you prefer to install `stud` globally for all users, you can use the traditional method:

```bash
sudo mv ./stud.phar /usr/local/bin/stud
sudo chmod +x /usr/local/bin/stud
```

**Note:** If you use this method, you will need to run `sudo stud update` to update the tool.

### Updating

To update `stud-cli` to the latest version, simply run:

```bash
stud update
```

The tool will automatically check for new releases, download the latest version, and replace your current installation. If you installed using the recommended method (user-owned directory), this will work seamlessly without requiring `sudo`.

You can preview the changelog of the latest available version (including breaking changes) without downloading by using the `--info` flag:

```bash
stud update --info
stud up -i
```

This is especially useful for checking breaking changes before updating.

### Configuration

Before using `stud-cli` for the first time, you need to configure your Jira connection details.

**Automatic Configuration Migration:** `stud-cli` includes an automatic migration system that updates your configuration format when the tool is updated. Global configuration migrations run automatically during `stud update`, and project-specific migrations run on-demand when you execute commands in a git repository. If mandatory configuration keys are missing, the tool will prompt you interactively or provide helpful error messages in non-interactive mode.

#### `stud config:init` (Alias: `stud init`)

**Description:** A first-time setup wizard that interactively prompts for your language preference, Jira URL, email, and API token. It provides a link to generate an Atlassian API token and saves these values to `~/.config/stud/config.yml`. The language setting controls the display language for all user-facing messages (defaults to English).

For detailed instructions on creating tokens and required permissions/scopes, see the [Token Setup Guide](#token-setup-guide) above.

At the end of the setup, the wizard will detect your shell (bash or zsh) and offer to set up shell auto-completion. If you choose to set it up, you'll receive instructions on how to complete the installation.

**Usage:**
```bash
stud config:init
stud init
```

#### `stud completion <shell>`

**Description:** Generates shell completion scripts for bash or zsh. This command is used to set up auto-completion for all `stud-cli` commands, including aliases like `init` (for `config:init`).

**Arguments:**
- `<shell>`: The shell type (`bash` or `zsh`)

**Usage:**
```bash
# Generate bash completion script
stud completion bash

# Generate zsh completion script
stud completion zsh

# To install, add to your shell configuration file:
# For bash:
eval "$(stud completion bash)" >> ~/.bashrc

# For zsh:
eval "$(stud completion zsh)" >> ~/.zshrc
```

**Note:** The easiest way to set up completion is through the `stud config:init` wizard, which will guide you through the installation process.

#### Token Setup Guide

This section provides detailed instructions for creating and configuring the API tokens required by `stud-cli`.

##### Jira API Token

**Required Permissions:**
- Read access to issues
- Read access to projects
- Read access to filters

**Creation Steps:**
1. Go to [Atlassian Account Settings > Security > API tokens](https://id.atlassian.com/manage-profile/security/api-tokens)
2. Click "Create API token"
3. Give it a label (e.g., "stud-cli")
4. Copy the generated token (you won't be able to see it again)
5. Paste it when prompted by `stud config:init`

**What it enables:**
- Viewing Jira issues, projects, and filters
- Reading issue descriptions and metadata
- All operations are read-only (no modifications to Jira)

**Storage:** Token is stored in `~/.config/stud/config.yml` (plain text)

##### Git Provider Token

`stud-cli` supports both GitHub and GitLab as Git hosting providers. During `stud config:init`, you'll be prompted to select your provider (`github` or `gitlab`).

**GitHub Token**

**Required Scopes:**
- `repo` scope (full control of private repositories)
- Required for: Creating PRs, adding comments, managing labels

**Creation Steps:**
1. Go to [GitHub Settings > Developer settings > Personal access tokens > Tokens (classic)](https://github.com/settings/tokens)
2. Click "Generate new token (classic)"
3. Give it a descriptive name (e.g., "stud-cli")
4. Select expiration period
5. Check the `repo` scope checkbox
6. Click "Generate token"
7. Copy the token immediately (you won't be able to see it again)
8. Paste it when prompted by `stud config:init`

**Note:** Fine-grained tokens are not currently supported. Use classic tokens.

**GitLab Token**

**Required Scopes:**
- `api` scope (full API access)
- Required for: Creating Merge Requests, adding comments/notes, managing labels

**Creation Steps:**
1. Go to [GitLab Settings > Access Tokens](https://gitlab.com/-/user_settings/personal_access_tokens) (or your GitLab instance URL)
2. Give it a descriptive name (e.g., "stud-cli")
3. Select expiration date (optional)
4. Check the `api` scope checkbox
5. Click "Create personal access token"
6. Copy the token immediately (you won't be able to see it again)
7. Paste it when prompted by `stud config:init`

**For Self-Hosted GitLab Instances:**
If you're using a self-hosted GitLab instance, you can optionally configure the instance URL in your config file:
```yaml
GIT_PROVIDER: gitlab
GIT_TOKEN: your_token_here
GITLAB_INSTANCE_URL: https://git.example.com  # Optional, defaults to gitlab.com
```

**What Git Provider Tokens Enable:**
- `stud submit`: Creating Pull Requests (GitHub) or Merge Requests (GitLab)
- `stud pr:comment`: Adding comments to PRs/MRs
- `stud branch:rename`: Managing PRs/MRs and labels during branch rename
- `stud branches:list` and `stud branches:clean`: PR/MR detection for branch status
- Label management and PR/MR operations

**Storage:** Token is stored in `~/.config/stud/config.yml` (plain text)

**Security Best Practices:**
- Use tokens with minimal required scopes
- Set appropriate expiration dates
- Rotate tokens periodically
- Never commit tokens to version control
- Restrict file permissions: `chmod 600 ~/.config/stud/config.yml`

### Usage

All `stud-cli` commands are executed via the `stud` executable. The general syntax is `stud <command> [arguments] [options]`.

#### Getting Help

Commands do not support the `--help` (or `-h`) option to display context-specific help directly in your terminal (due to a Castor bug). To see detailed information about the command, its options, and usage examples extracted from this documentation, use `stud help <command>` instead.

```bash
stud help commit
stud help submit
stud help items:list
stud help co  # Works with aliases too
```

#### Jira Information Commands

These commands help you browse and view your Jira work items.

-   **`stud projects:list`** (Alias: `stud pj`)
    -   **Description:** Lists all Jira projects visible to your configured user.
    -   **Usage:**
        ```bash
        stud projects:list
        stud pj
        ```

-   **`stud items:list`** (Alias: `stud ls`)
    -   **Description:** Lists active work items, using Jira's status categories for more flexible filtering. This is your main "dashboard" command.
    -   **Options:**
        -   `--all` or `-a`: List items for all users (overrides default assignee filter).
        -   `--project <key>` or `-p <key>`: Filter items by a specific project key (e.g., `PROJ`).
        -   `--sort <value>` or `-s <value>`: Sort results by Key or Status (case-insensitive). When not provided, items are sorted by updated DESC from Jira.
    -   **Usage:**
        ```bash
        stud items:list
        stud ls -a
        stud items:list --project PROJ
        stud ls -p MYPROJ -a
        stud ls --sort Key
        stud ls -s Status
        stud ls -a -s Key
        ```

-   **`stud items:show <key>`** (Alias: `stud sh <key>`)
    -   **Description:** Shows detailed information for a specific Jira work item.
    -   **Argument:** `<key>` (e.g., `PROJ-123`)
    -   **Usage:**
        ```bash
        stud items:show PROJ-123
        stud sh BUG-456
        ```

-   **`stud items:search <jql>`** (Alias: `stud search <jql>`)
    -   **Description:** Search for issues using JQL (Jira Query Language).
    -   **Argument:** `<jql>` (e.g., `"project = PROJ and status = Done"`)
    -   **Usage:**
        ```bash
        stud items:search "project = PROJ and status = Done"
        stud search "assignee = currentUser()"
        ```

-   **`stud items:transition [<key>]`** (Alias: `stud tx [<key>]`)
    -   **Description:** Transitions a Jira work item to a different status. If the key is not provided, the command attempts to detect it from the current Git branch name.
    -   **Argument:** `<key>` (optional, e.g., `PROJ-123`)
    -   **Usage:**
        ```bash
        stud items:transition PROJ-123
        stud tx BUG-456
        stud tx  # Will detect key from current branch
        ```
    -   **Behavior:**
        -   If a key is provided, it will be used directly (converted to uppercase).
        -   If no key is provided, the command will attempt to detect the Jira key from the current Git branch name.
        -   If a key is detected from the branch, you will be asked to confirm before proceeding.
        -   If no key is detected or you reject the detected key, you will be prompted to enter a Jira work item key.
        -   The command validates the key format (must match pattern like `PROJ-123`).
        -   All available transitions for the issue are displayed, and you can select one to apply.
        -   The command does NOT auto-assign the issue (only transitions).

-   **`stud filters:list`** (Alias: `stud fl`)
    -   **Description:** Lists all available Jira filters with their names and descriptions, sorted by name in ascending order.
    -   **Usage:**
        ```bash
        stud filters:list
        stud fl
        ```

-   **`stud filters:show <filterName>`** (Alias: `stud fs <filterName>`)
    -   **Description:** Retrieve issues from a saved Jira filter by filter name. Displays issues in a table with Key, Status, Priority (conditional), Description, and Jira URL columns. The Priority column is only shown when at least one issue has a priority assigned.
    -   **Argument:** `<filterName>` (e.g., `"My Filter"`). Filter names with spaces should be quoted.
    -   **Usage:**
        ```bash
        stud filters:show "My Filter"
        stud fs "My Filter"
        ```

#### Git Workflow Commands

These commands integrate directly with your local Git repository to streamline your development workflow.

-   **`stud items:start <key>`** (Alias: `stud start <key>`)
    -   **Description:** The core "start work" workflow. Creates a new Git branch based on a Jira issue. If `JIRA_TRANSITION_ENABLED` is enabled in your configuration, the command will automatically assign the issue to you and transition it to 'In Progress'. The transition ID is cached per project in `.git/stud.config` to avoid repeated prompts. The command now automatically detects existing branches (local or remote) and switches to them instead of creating duplicates.
    -   **Argument:** `<key>` (e.g., `PROJ-123`)
    -   **Usage:**
        ```bash
        stud items:start PROJ-123
        stud start BUG-456
        ```
    -   **Transition Behavior:**
        -   On first run for a project, you'll be prompted to select the appropriate 'In Progress' transition from available options.
        -   Your choice is saved to `.git/stud.config` for future use in the same project.
        -   Subsequent runs will use the cached transition ID automatically.
        -   If no 'In Progress' transitions are available, a warning is displayed and branch creation continues.
    -   **Branch Detection:**
        -   If a local branch matching the issue key exists, the command switches to it instead of creating a new branch.
        -   If a remote branch exists but no local branch, the command creates a local tracking branch from the remote.
        -   Remote branches are prioritized over local branches when both exist.

-   **`stud items:takeover <key>`** (Alias: `stud to <key>`)
    -   **Description:** Takes over an issue from another user. Assigns the issue to you, detects existing branches (prioritizing remote over local), and switches to them if found. If no branches exist, prompts to start fresh using `items:start`. The command also checks branch status compared to remote and base branch, warns about wrong base branches or diverged commits, and automatically pulls with rebase when behind remote (if no local commits exist).
    -   **Argument:** `<key>` (e.g., `PROJ-123`)
    -   **Usage:**
        ```bash
        stud items:takeover PROJ-123
        stud to BUG-456
        ```
    -   **Behavior:**
        -   Blocks execution if working directory has uncommitted changes.
        -   Assigns the issue to the current user (warns on failure but continues).
        -   Searches for branches matching pattern `{prefix}/{KEY}-*` (feat, fix, chore).
        -   If multiple branches found: lists all (remote prioritized) and lets you choose.
        -   If single remote branch found: auto-selects after confirmation.
        -   If only local branches: lists all and lets you choose.
        -   If already on target branch: skips checkout and only checks status.
        -   Shows branch status (behind/ahead/sync) compared to remote and develop.
        -   Warns if branch is based on different base branch than expected.
        -   Warns if local branch has diverged from remote (has local commits).
        -   Pulls from remote (with rebase) if behind and no local commits.
        -   If no branches found: prompts to start fresh (calls `items:start`).

-   **`stud branch:rename`** (Alias: `stud rn`)
    -   **Description:** Renames a branch, optionally regenerating the name from a Jira issue key or using an explicit name. The command handles both local and remote branches, updates associated Pull Requests, and manages branch synchronization.
    -   **Arguments:**
        -   `<branch>` (optional): The branch to rename. Defaults to the current branch if not provided.
        -   `<key>` (optional): The Jira issue key to regenerate the branch name from (e.g., `PROJ-123`). If not provided, the key will be extracted from the current branch name.
    -   **Options:**
        -   `--name <name>` or `-n <name>`: Explicit new branch name (no prefix will be added). This option takes precedence over the key argument.
    -   **Usage:**
        ```bash
        # Rename current branch using its issue key (regenerate from Jira)
        stud rn
        
        # Rename specific branch using a Jira key
        stud rn feat/OLD-123-old ACME-4067
        
        # Rename current branch to exact name
        stud rn --name custom-branch-name
        
        # Rename specific branch to exact name
        stud rn feat/OLD-123-old --name new-branch-name
        ```
    -   **Behavior:**
        -   The command blocks execution if the working directory has uncommitted changes.
        -   Validates that the new branch name doesn't already exist (local or remote).
        -   Validates explicit branch names follow Git naming rules.
        -   Handles local-only branches (renames local, informs about missing remote).
        -   Handles remote-only branches (prompts to rename remote only, default yes).
        -   Checks branch synchronization before renaming remote.
        -   Prompts to rebase if local is behind remote (default yes, bypass if `--quiet`).
        -   Detects associated Pull Request (if GithubProvider available).
        -   Attempts to update PR head branch via GitHub API (may not be supported by GitHub).
        -   Adds comment to PR explaining the rename.
        -   Handles PR update failures gracefully (warns but continues).
        -   Shows confirmation message with current/new names and actions.
        -   Asks for confirmation (default yes, bypass if `--quiet`).
        -   Suggests creating PR if none exists after rename.

-   **`stud branches:list`** (Alias: `stud bl`)
    -   **Description:** Lists all local branches with their status (merged, stale, active PR, or active). Shows whether each branch exists on remote and has an associated Pull Request.
    -   **Usage:**
        ```bash
        stud branches:list
        stud bl
        ```
    -   **Status Definitions:**
        -   **merged**: Branch is merged into develop and exists on remote
        -   **stale**: Branch is merged into develop but doesn't exist on remote
        -   **active-pr**: Branch has an associated Pull Request (open or closed)
        -   **active**: Branch is not merged and has commits

-   **`stud branches:clean`** (Alias: `stud bc`)
    -   **Description:** Interactive cleanup of merged/stale branches. Identifies branches that are merged into develop and don't exist on remote, then prompts for confirmation before deletion. Protected branches (develop, main, master) are never deleted.
    -   **Options:**
        -   `--quiet` or `-q`: Remove all matching branches without prompting (non-interactive mode).
    -   **Usage:**
        ```bash
        # Interactive mode (prompts for confirmation)
        stud branches:clean
        stud bc
        
        # Non-interactive mode (no prompts)
        stud branches:clean --quiet
        stud bc -q
        ```
    -   **Branch Deletion Decision Matrix:**
        
        The command evaluates each local branch against the following criteria to determine if it should be deleted:
        
        | Protected? | Current Branch? | Merged into develop? | Has Open PR? | Exists on Remote? | Action |
        |------------|-----------------|----------------------|--------------|-------------------|--------|
        | ✅ Yes | - | - | - | - | ❌ **SKIP** (never deleted) |
        | ❌ No | ✅ Yes | - | - | - | ⚠️ **SKIP** (notify user, switch branch first) |
        | ❌ No | ❌ No | ❌ No | - | - | ❌ **SKIP** (not merged) |
        | ❌ No | ❌ No | ✅ Yes | ✅ Yes | - | ❌ **SKIP** (has open PR) |
        | ❌ No | ❌ No | ✅ Yes | ❌ No | ✅ Yes | ✅ **CANDIDATE** (prompt for local + remote deletion) |
        | ❌ No | ❌ No | ✅ Yes | ❌ No | ❌ No | ✅ **CANDIDATE** (delete local only) |
        
        **Notes:**
        - **Protected branches:** `develop`, `main`, `master` are always skipped
        - **Current branch:** Cannot be deleted (you must switch branches first)
        - **Merge check:** Uses `git branch --merged develop` to determine if branch is merged
        - **PR check:** If GitHub provider is available, checks for open PRs (closed PRs don't prevent deletion)
        - **Remote branches:** In interactive mode, you'll be prompted separately to delete remote branches
        - **Quiet mode:** Only deletes local branches, never prompts for remote deletion
        - **Error handling:** If merge check or PR check fails, the branch is skipped (safe default)
        
    -   **Behavior:**
        -   Scans for branches merged into develop that don't exist on remote
        -   Never deletes protected branches (develop, main, master)
        -   In interactive mode, displays list of branches to be deleted and prompts for confirmation
        -   In quiet mode, deletes all matching branches without prompting
        -   Handles deletion errors gracefully (logs warning and continues with other branches)
        -   For branches that exist on remote, prompts separately to delete the remote branch as well

-   **`stud commit`** (Alias: `stud co`)
    -   **Description:** Guides you through making a conventional commit message.
    -   **Options:**
        -   `--new`: Create a new logical commit instead of a fixup.
        -   `--message <message>` or `-m <message>`: Bypass the interactive prompter and use the provided message for the commit.
    -   **Usage:**
        ```bash
        stud commit
        stud co --new
        stud commit -m "feat: My custom message"
        ```

-   **`stud please`** (Alias: `stud pl`)
    -   **Description:** A power-user, safe force-push using `git push --force-with-lease`.
    -   **Usage:**
        ```bash
        stud please
        stud pl
        ```

-   **`stud flatten`** (Alias: `stud ft`)
    -   **Description:** Automatically squash all `fixup!` and `squash!` commits into their target commits. This command performs a non-interactive rebase with autosquash, eliminating the need to manually edit the interactive rebase file. The command will fail if there are uncommitted changes in the working directory, and will warn that history will be rewritten (requiring a `stud please` push afterward).
    -   **Usage:**
        ```bash
        stud flatten
        stud ft
        ```
    -   **Note:** This command rewrites commit history. After running `stud flatten`, you will need to use `stud please` to force-push your changes.

-   **`stud status`** (Alias: `stud ss`)
    -   **Description:** A quick "where am I?" dashboard, showing your current Jira and Git status.
    -   **Usage:**
        ```bash
        stud status
        stud ss
        ```

-   **`stud submit`** (Alias: `stud su`)
    -   **Description:** Submits your work as a pull request. Pushes the current branch to the remote repository and creates a pull request on GitHub. The PR description is automatically converted from Jira's HTML format to Markdown for better readability on GitHub.
    -   **Options:**
        -   `--draft` or `-d`: Create a Draft Pull Request (marked as "Draft" on GitHub).
        -   `--labels <labels>`: Comma-separated list of labels to apply to the Pull Request. If a label doesn't exist, you'll be prompted to create it, ignore it, or retry with a corrected list.
    -   **Usage:**
        ```bash
        stud submit
        stud su
        stud submit --draft
        stud su -d
        stud submit --labels "bug,enhancement"
        stud submit --draft --labels "bug,ui"
        ```
    -   **Note:** PR descriptions are automatically converted from Jira's HTML format to Markdown. This improves readability on GitHub by removing Jira-specific HTML artifacts and formatting issues. If conversion fails, the original HTML is used as a fallback.

-   **`stud pr:comment`** (Alias: `stud pc`)
    -   **Description:** Posts a comment to the active Pull Request associated with the current branch. Supports piping content from STDIN (preferred for automation) or providing a direct message argument.
    -   **Argument:** `<message>` (optional): The comment message. If not provided, content will be read from STDIN.
    -   **Usage:**
        ```bash
        # Piped input (preferred for automation/AI workflows)
        echo "Report content" | stud pr:comment
        cat report.md | stud pr:comment
        
        # Direct argument (manual/quick workflow)
        stud pr:comment "Manual message"
        stud pc "Quick comment"
        
        # Using alias with piped input
        echo "Comment text" | stud pc
        ```
    -   **Note:** The command automatically finds the active Pull Request for the current branch. If no PR is found or no input is provided, the command will fail with a clear error message.

-   **`stud update`** (Alias: `stud up`)
    -   **Description:** Checks for and installs new versions of the tool. Automatically detects the repository from your git remote and downloads the latest release from GitHub.
    -   **Options:**
        -   `--info` or `-i`: Preview the changelog of the latest available version without downloading or installing. Useful for checking breaking changes before updating.
    -   **Usage:**
        ```bash
        stud update
        stud up
        stud update --info
        stud up -i
        ```
    -   **Note:** If the binary is not writable, you may need to run with elevated privileges: `sudo stud update`

-   **`stud cache:clear`** (Alias: `stud cc`)
    -   **Description:** Clears the update check cache file to force a version check on the next command execution. This is useful for maintainers and developers testing the update workflow without waiting 24 hours for the cache to expire.
    -   **Usage:**
        ```bash
        stud cache:clear
        stud cc
        ```
    -   **Note:** The cache file is located at `~/.cache/stud/last_update_check.json`. If the file doesn't exist, the command will report that the cache was already clear.

#### Release Commands

These commands help you manage the release process.

-   **`stud release [<version>]`** (Alias: `stud rl [<version>]`)
    -   **Description:** Creates a new release branch and bumps the version in `composer.json`. Supports automatic Semantic Versioning (SemVer) bumping via flags.
    -   **Argument:** `<version>` (optional): The new version (e.g., `1.2.0`). If not provided, version is calculated automatically based on flags.
    -   **Options:**
        -   `--major` or `-M`: Increment major version (X.0.0)
        -   `--minor` or `-m`: Increment minor version (X.Y.0)
        -   `--patch` or `-b`: Increment patch version (X.Y.Z). This is the default if no flags are provided.
        -   `--publish` or `-p`: Publish the release branch to the remote
    -   **Usage:**
        ```bash
        # Automatic patch bump (default)
        stud release
        stud rl
        
        # Explicit version
        stud release 1.2.0
        stud rl 1.2.0
        
        # SemVer flags
        stud release --patch    # or -b
        stud release --minor     # or -m
        stud release --major     # or -M
        
        # With publish flag
        stud release --minor --publish
        ```

-   **`stud deploy`** (Alias: `stud mep`)
    -   **Description:** Deploys the current release branch. This merges the release into `main`, tags it, and updates `develop`.
    -   **Options:**
        -   `--clean`: Clean up merged branches after deployment (non-interactive).
    -   **Usage:**
        ```bash
        stud deploy
        stud mep
        stud deploy --clean
        ```

### User Troubleshooting

-   **`Configuration file not found`:** Run `stud config:init` to set up your Jira credentials.
-   **`Could not find Jira issue with key`:** Double-check the Jira key you provided. Ensure it's correct and you have access to the issue.
-   **`Could not find a Jira key in your current branch name`:** Ensure your branch name follows the `prefix/PROJ-123-summary` format, or use `stud items:start <key>` to create a new branch.
-   **`Class 'DOMDocument' not found` or `Fatal error: Class 'DOMDocument' not found`:** The PHP XML extension is missing. Install it using:
    ```bash
    # Ubuntu/Debian
    sudo apt-get install php-xml
    # Fedora/RHEL
    sudo dnf install php-xml
    # macOS (Homebrew)
    brew install php-xml
    ```
    After installation, restart your terminal. Verify with: `php -m | grep xml`
-   **`GitHub API Error` or `Permission denied` when using `stud submit`:** Your GitHub token may be missing the `repo` scope. Generate a new token with `repo` scope and update your configuration with `stud config:init`.
-   **`Jira API Error` or `Unauthorized`:** Your Jira API token may be invalid or expired. Generate a new token and update your configuration with `stud config:init`.

---

Feel free to contribute or suggest improvements!