# stud-cli: Jira & Git Workflow Streamliner

`stud-cli` is a command-line interface tool designed to streamline a developer's daily workflow by tightly integrating Jira work items with local Git repository operations. It guides you through the "golden path" of starting a task, making conventional commits, and preparing your work for submission, all from the command line.

## Table of Contents

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

### Developer Setup

To get `stud-cli` running from source, the process is simple as all dependencies are managed by Composer.

1.  **Clone the Repository:**
    ```bash
    git clone <your-repository-url>
    cd stud-cli
    ```

2.  **Install All Dependencies:**
    This single command will install all required PHP packages, including the Castor framework and the Box PHAR compiler, as they are defined in `composer.json`.
    ```bash
    composer install
    ```

3.  **Run the Tool:**
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

#### `stud config:init` (Alias: `stud init`)

**Description:** A first-time setup wizard that interactively prompts for your language preference, Jira URL, email, and API token. It provides a link to generate an Atlassian API token and saves these values to `~/.config/stud/config.yml`. The language setting controls the display language for all user-facing messages (defaults to English).

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
    -   **Usage:**
        ```bash
        stud items:list
        stud ls -a
        stud items:list --project PROJ
        stud ls -p MYPROJ -a
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
    -   **Description:** The core "start work" workflow. Creates a new Git branch based on a Jira issue. If `JIRA_TRANSITION_ENABLED` is enabled in your configuration, the command will automatically assign the issue to you and transition it to 'In Progress'. The transition ID is cached per project in `.git/stud.config` to avoid repeated prompts.
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

-   **`stud submit`** (Alias: `stud sub`)
    -   **Description:** Submits your work as a pull request. Pushes the current branch to the remote repository and creates a pull request on GitHub.
    -   **Options:**
        -   `--draft` or `-d`: Create a Draft Pull Request (marked as "Draft" on GitHub).
        -   `--labels <labels>`: Comma-separated list of labels to apply to the Pull Request. If a label doesn't exist, you'll be prompted to create it, ignore it, or retry with a corrected list.
    -   **Usage:**
        ```bash
        stud submit
        stud sub
        stud submit --draft
        stud sub -d
        stud submit --labels "bug,enhancement"
        stud submit --draft --labels "bug,ui"
        ```

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
    -   **Usage:**
        ```bash
        stud deploy
        stud mep
        ```

### User Troubleshooting

-   **`Configuration file not found`:** Run `stud config:init` to set up your Jira credentials.
-   **`Could not find Jira issue with key`:** Double-check the Jira key you provided. Ensure it's correct and you have access to the issue.
-   **`Could not find a Jira key in your current branch name`:** Ensure your branch name follows the `prefix/PROJ-123-summary` format, or use `stud items:start <key>` to create a new branch.

---

Feel free to contribute or suggest improvements!