# Configuration

Run the first-time wizard after installing:

```bash
stud init
```

The wizard creates or updates `~/.config/stud/config.yml`. You choose which Git hosts and issue-tracker backends you use; credentials are collected only for the providers you select.

## Init wizard menus

After language selection, `stud config:init` shows two numbered menus (0 / 1 / 2). Your choices set `GIT_PROVIDERS` and `ISSUE_TRACKER_PROVIDERS` in global config.

### Git provider menu

| Choice | `GIT_PROVIDERS` | Credentials collected |
|--------|-----------------|----------------------|
| 0 GitHub | `github` | GitHub PAT (optional if already stored) |
| 1 GitLab | `gitlab` | GitLab PAT (optional if already stored) |
| 2 Both | `github`, `gitlab` | GitHub PAT and GitLab PAT |

Per-repository overrides remain available in `.git/stud.config` via `stud config:project-init`.

### Issue-tracker provider menu

| Choice | `ISSUE_TRACKER_PROVIDERS` | Credentials collected |
|--------|---------------------------|----------------------|
| 0 Jira | `jira` | Jira URL, email, API token; optional transition-to-In-Progress flag |
| 1 Linear | `linear` | Linear API key |
| 2 Both | `jira`, `linear` | Jira trio + transition flag, then Linear API key |

Jira is not required when you select Linear only. Legacy configs with `WORK_ITEM_PROVIDERS` are migrated automatically on the next command that loads global config.

### Agent mode

Pass provider choices and credentials as JSON instead of interactive prompts:

```bash
echo '{"gitProviders":["github"],"issueTrackerProviders":["jira"],"jiraUrl":"https://example.atlassian.net","jiraEmail":"you@example.com","jiraApiToken":"..."}' | stud config:init --agent
```

Deprecated agent keys `workItemProviders` / `workItemProvider` are still accepted and mapped to the canonical names. Run `echo '{"command":"config:init"}' | stud help --agent` for the full input schema.

## Provider lists

Global config stores which integrations are active:

| Key | Values | Purpose |
|-----|--------|---------|
| `GIT_PROVIDERS` | `github`, `gitlab` | Which Git hosts you use for PR/MR workflow |
| `ISSUE_TRACKER_PROVIDERS` | `jira`, `linear` | Which issue-tracker backends you use |
| `LINEAR_API_KEY` | secret | Linear API key when `linear` is listed |

Legacy `WORK_ITEM_PROVIDERS` is read for compatibility and renamed by migration. Existing credential keys are never removed.

Project config can override the issue-tracker provider with `issueTrackerProvider` (`jira`, `linear`, or `auto`) in `.git/stud.config`.

### Dual-provider resolution

When both Jira and Linear are configured globally:

| `issueTrackerProvider` | Behavior |
|------------------------|----------|
| `jira` | Always use Jira for `items:*` in this repo |
| `linear` | Always use Linear |
| `auto` | Resolve from issue-key prefix (`projectKey` / `linearTeamKey`) or fail with an actionable error when ambiguous |

Override a single command without changing config:

```bash
stud items:show SCI-123 --provider jira
stud items:show ENG-42 --provider linear
echo '{"key":"ENG-42","provider":"linear"}' | stud items:show --agent
```

`--provider` accepts `jira` or `linear` only (not `auto`). See [Work items](../features/work-items.md).

Search and saved-view commands depend on the active provider: **JQL applies to Jira only**; Linear uses plain search terms and custom views. See [Work items — search and saved views](../features/work-items.md).

## Jira

You need:

- Jira URL
- Jira email address
- Atlassian API token

Create an Atlassian token at [Atlassian Account Settings > Security > API tokens](https://id.atlassian.com/manage-profile/security/api-tokens).

Jira access enables reading issues, projects, filters, attachments, and Confluence content on the same Atlassian site when those commands are used.

Project config (`.git/stud.config`):

| Key | Purpose |
|-----|---------|
| `projectKey` | Jira project key (issue prefix, default scope) |
| `transitionId` | Workflow transition applied by `stud items:start` / `items:transition` |
| `JIRA_DEFAULT_PROJECT` | Default project for create when not passed on CLI |

Discover workflow transitions interactively during `stud config:project-init`, or list them with `stud projects:workflow`.

## Linear setup

When `ISSUE_TRACKER_PROVIDERS` includes `linear`, configure `LINEAR_API_KEY` during `stud init` (global). Repository-specific Linear fields live in `.git/stud.config`.

### API key

Create a personal API key in Linear (**Settings → API**). stud stores it as `LINEAR_API_KEY` in `~/.config/stud/config.yml`. `stud config:validate` pings Linear when the key is present and validation is not skipped.

### Team key and scope

Linear **teams** map to Jira **projects** in stud-cli (see [ADR-022](../adr-022-jira-linear-work-item-scope-mapping.md)):

| Key | Purpose |
|-----|---------|
| `projectKey` | Team key used in issue identifiers (e.g. `ENG-42`) |
| `linearTeamKey` | Optional explicit Linear team key when it differs from `projectKey` |

Use `stud projects:list` to list teams you can access.

### Workflow state and issue types

| Key | Purpose |
|-----|---------|
| `linearStartStateId` | Workflow state applied when starting work (`stud items:start`) |
| `linearTypeLabelGroupId` | Label group used for issue-type → branch-prefix mapping |
| `linearTypeBranchPrefixes` | Map of type label → branch prefix (e.g. `Story: feat`) |

`stud config:project-init` can guide you through these when Linear is the effective provider. Discover options without guessing UUIDs:

```bash
stud projects:workflow --project ENG
stud projects:labels --project ENG
stud projects:labels --project ENG --groups-only
```

Agent mode:

```bash
echo '{"project":"ENG"}' | stud projects:workflow --agent
echo '{"project":"ENG","groupsOnly":true}' | stud projects:labels --agent
```

## Project configuration

Repository-specific values live in `.git/stud.config`.

```bash
stud config:project-init
stud cpi
```

When both issue trackers are configured globally, the wizard prompts for `issueTrackerProvider` (`jira`, `linear`, or `auto`).

With **`auto`**, the wizard runs **both** Jira and Linear metadata pickers in one session: Jira transition (via `projectKey`), optional **`linearTeamKey`** when your Linear team differs from the Jira project, then Linear start state, LabelGroup, and branch-prefix map (via the resolved Linear team key).

Example dual-`auto` config:

```yaml
issueTrackerProvider: auto
projectKey: SCI
JIRA_DEFAULT_PROJECT: SCI
transitionId: 21
linearTeamKey: ENG
linearStartStateId: "<uuid>"
linearTypeLabelGroupId: "<uuid>"
linearTypeBranchPrefixes:
  Story: feat
```

Discovery commands infer the provider from `--project` when `issueTrackerProvider` is `auto`: pass the Jira project key for Jira workflow, or the Linear team key (or `linearTeamKey` when set) for Linear labels/states.

Agent mode example:

```bash
echo '{"projectKey":"SCI","issueTrackerProvider":"auto","linearTeamKey":"ENG","linearStartStateId":"state-uuid","baseBranch":"develop"}' | stud config:project-init --agent
```

## Validate setup

`stud config:validate` pings only the providers listed in your global config. Jira-only setups behave as before; Linear-only setups skip Jira; dual-provider configs validate each configured integration.

```bash
stud config:validate
stud config:validate --skip-jira
stud config:validate --skip-git
stud config:validate --skip-linear
```

Linear-only CI often uses `skipJira: true` in agent JSON — see [GitHub Actions](../github-actions.md).

## Inspect configuration safely

```bash
stud config:show
stud config:show -k baseBranch
stud config:show -k JIRA_URL -q
stud config:show -k issueTrackerProvider
stud config:show -k linearTeamKey
```

Secrets are redacted in shared output.

## Provider tokens

Git provider setup is split by provider:

- [GitHub integration](../integrations/github.md)
- [GitLab integration](../integrations/gitlab.md)
