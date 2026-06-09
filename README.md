<div align="center">
  <img src="src/resources/logo-300.png" alt="Stud-Cli Logo" width="300">
</div>

# stud-cli

`stud-cli` streamlines daily Jira and Git work from the terminal. It helps you find work items, start branches, create conventional commits, submit pull requests, work with Confluence pages, and run the same flows safely in automation.

## Install

PHAR is the recommended default install path. It is the most validated distribution and supports `stud update`.

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash
```

You can make the mode explicit:

```bash
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash -s -- --phar
curl -fsSL https://raw.githubusercontent.com/Studapart/stud-cli/develop/setup-stud.sh | bash -s -- --portable
```

Use `--portable` only when you deliberately want the bundled-runtime artifact for Linux amd64 / WSL2 or macOS Apple Silicon. Versioned portable installs support `stud update`; rerun the latest portable installer to repair older legacy portable layouts.

## Choose Your Path

| Need | Start Here |
| --- | --- |
| Install on Linux or WSL2 | [Linux / WSL setup](documentation/setup/linux-wsl.md) |
| Install on macOS | [macOS setup](documentation/setup/macos.md) |
| Understand PHAR vs portable | [Setup overview](documentation/setup/index.md) |
| Configure Jira and providers | [Configuration](documentation/setup/configuration.md) |
| Configure GitHub token and PR workflow | [GitHub integration](documentation/integrations/github.md) |
| Configure GitLab token and MR workflow | [GitLab integration](documentation/integrations/gitlab.md) |
| Understand the full idea-to-PR workflow | [Workflow playbook](documentation/features/workflow-playbook.md) |
| Use Jira work item commands | [Jira work items](documentation/features/jira-work-items.md) |
| Use Git workflow commands | [Git workflow](documentation/features/git-workflow.md) |
| Submit and comment on pull requests | [Pull requests](documentation/features/pull-requests.md) |
| Use Confluence commands | [Confluence](documentation/features/confluence.md) |
| Look up every command option/API field | [Command reference](documentation/reference/commands.md) |
| Run stud in scripts or CI | [Automation](documentation/features/automation.md) |
| Contribute to stud-cli | [Development guide](documentation/development/index.md) |

## Common Commands

```bash
stud init                  # first-time configuration
stud ls                    # list your Jira work items
stud sh SCI-123            # show a work item
stud start SCI-123         # create or switch to a branch for a work item
stud co                    # create a conventional commit
stud su                    # push and open a pull request
stud help                  # list commands
stud help submit           # show command-specific help
```

## Documentation Index

- [Setup overview](documentation/setup/index.md)
- [Linux / WSL setup](documentation/setup/linux-wsl.md)
- [macOS setup](documentation/setup/macos.md)
- [PHAR install](documentation/setup/phar.md)
- [Portable install](documentation/setup/portable.md)
- [Configuration](documentation/setup/configuration.md)
- [Feature overview](documentation/features/index.md)
- [Workflow playbook](documentation/features/workflow-playbook.md)
- [Command reference](documentation/reference/commands.md)
- [GitHub Actions with stud-cli](documentation/github-actions.md)
- [Development guide](documentation/development/index.md)
- [Engineering conventions](CONVENTIONS.md)
- [Architecture decisions](documentation/adr-005-responder-pattern-architecture.md)

See [CONTRIBUTING](CONTRIBUTING.md) for contribution expectations and [CODE_OF_CONDUCT](CODE_OF_CONDUCT.md) for community standards.
