# Configuration

Run the first-time wizard after installing:

```bash
stud init
```

The wizard creates or updates `~/.config/stud/config.yml`. You choose which Git hosts and work-item backends you use; credentials are collected only for the providers you select.

## Init wizard menus

After language selection, `stud config:init` shows two numbered menus (0 / 1 / 2). Your choices set `GIT_PROVIDERS` and `WORK_ITEM_PROVIDERS` in global config.

### Git provider menu

| Choice | `GIT_PROVIDERS` | Credentials collected |
|--------|-----------------|----------------------|
| 0 GitHub | `github` | GitHub PAT (optional if already stored) |
| 1 GitLab | `gitlab` | GitLab PAT (optional if already stored) |
| 2 Both | `github`, `gitlab` | GitHub PAT and GitLab PAT |

Per-repository overrides remain available in `.git/stud.config` via `stud config:project-init`.

### Work-item provider menu

| Choice | `WORK_ITEM_PROVIDERS` | Credentials collected |
|--------|----------------------|----------------------|
| 0 Jira | `jira` | Jira URL, email, API token; optional transition-to-In-Progress flag |
| 1 Linear | `linear` | Linear API key |
| 2 Both | `jira`, `linear` | Jira trio + transition flag, then Linear API key |

Jira is not required when you select Linear only. Legacy configs without provider lists are migrated automatically on the next command that loads global config.

### Agent mode

Pass provider choices and credentials as JSON instead of interactive prompts:

```bash
echo '{"gitProviders":["github"],"workItemProviders":["jira"],"jiraUrl":"https://example.atlassian.net","jiraEmail":"you@example.com","jiraApiToken":"..."}' | stud config:init --agent
```

Run `echo '{"command":"config:init"}' | stud help --agent` for the full input schema.

## Provider lists

Global config stores which integrations are active:

| Key | Values | Purpose |
|-----|--------|---------|
| `GIT_PROVIDERS` | `github`, `gitlab` | Which Git hosts you use for PR/MR workflow |
| `WORK_ITEM_PROVIDERS` | `jira`, `linear` | Which work-item backends you use |
| `LINEAR_API_KEY` | secret | Linear API key when `linear` is listed |

Legacy configs without these keys are migrated automatically on the next command that loads global config. Existing credential keys are never removed.

Project config can override the work-item provider with `workItemProvider` (`jira`, `linear`, or `auto`) in `.git/stud.config`.

## Jira

You need:

- Jira URL
- Jira email address
- Atlassian API token

Create an Atlassian token at [Atlassian Account Settings > Security > API tokens](https://id.atlassian.com/manage-profile/security/api-tokens).

Jira access enables reading issues, projects, filters, attachments, and Confluence content on the same Atlassian site when those commands are used.

## Linear

When `WORK_ITEM_PROVIDERS` includes `linear`, configure `LINEAR_API_KEY` during `stud init`. Linear connectivity in `stud config:validate` is reported as skipped until the Linear client performs live checks.

## Project Configuration

Repository-specific values live in `.git/stud.config`.

```bash
stud config:project-init
stud cpi
```

Agent mode is available for automation:

```bash
echo '{"projectKey":"SCI","baseBranch":"develop"}' | stud config:project-init --agent
```

## Validate Setup

`stud config:validate` pings only the providers listed in your global config. Jira-only setups behave as before; Linear-only setups skip Jira; dual-provider configs validate each configured integration.

```bash
stud config:validate
stud config:validate --skip-jira
stud config:validate --skip-git
stud config:validate --skip-linear
```

## Inspect Configuration Safely

```bash
stud config:show
stud config:show -k baseBranch
stud config:show -k JIRA_URL -q
stud config:show -k workItemProvider
```

Secrets are redacted in shared output.

## Provider Tokens

Git provider setup is split by provider:

- [GitHub integration](../integrations/github.md)
- [GitLab integration](../integrations/gitlab.md)
