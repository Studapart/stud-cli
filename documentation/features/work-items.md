# Work items

Issue-tracker commands (`stud items:*`, `stud filters:*`, `stud projects:*`) use **Jira** or **Linear** depending on repository and global configuration. This page covers provider resolution, search, saved views, and everyday create/show/update workflows for both backends.

Setup: [Configuration](../setup/configuration.md). Scope mapping: [ADR-022](../adr-022-jira-linear-work-item-scope-mapping.md). Command details: [generated reference](../reference/commands.md).

## Which provider runs?

| Config | Provider used |
|--------|----------------|
| `issueTrackerProvider: jira` | Jira |
| `issueTrackerProvider: linear` | Linear |
| `issueTrackerProvider: auto` | Resolved from `ISSUE_TRACKER_PROVIDERS`, credentials, and issue-key prefix |
| Global config lists one provider only | That provider |

Run `stud config:show -k issueTrackerProvider` (or inspect `.git/stud.config`) when scripts must know which API dialect to use.

### `--provider` override

Force Jira or Linear on a single invocation (CLI flag or agent JSON `provider`):

```bash
stud items:show SCI-123 --provider jira
stud items:show ENG-42 --provider linear
echo '{"key":"ENG-42","provider":"linear"}' | stud items:show --agent
```

Allowed values: `jira`, `linear`. Explicit `auto` is rejected — set `issueTrackerProvider: auto` in project config instead.

Essential `items:*` commands that accept `--provider` include `items:show`, `items:create`, `items:update`, `items:transition`, `items:start`, and `items:list`. See `echo '{"command":"items:show"}' | stud help --agent`.

## Search (`items:search` / `stud search`)

### Jira — JQL

Jira accepts full **JQL** (Jira Query Language):

```bash
stud search "project = SCI AND statusCategory != Done"
stud items:search "assignee = currentUser() AND status = 'In Progress'"
echo '{"jql":"project = SCI AND statusCategory != Done"}' | stud search --agent
```

Use Jira field names, functions (`currentUser()`), and operators as in the Jira UI. Invalid JQL returns an error from the Jira API.

### Linear — plain search term

Linear does **not** accept JQL. Pass a **free-text search term** (same idea as Linear’s issue search box):

```bash
stud search "login bug"
stud items:search "payment timeout"
echo '{"jql":"login bug"}' | stud search --agent
```

The term is sent to Linear’s `searchIssues` GraphQL API. Syntax is keyword search, not a structured query language.

### Agent JSON (`items:search --agent`)

The input and output shapes are **the same for both providers** (backward compatible):

| Field | Jira meaning | Linear meaning |
|-------|----------------|----------------|
| Input `jql` | JQL string | Search term (key name unchanged) |
| Output `data.jql` | Echo of the JQL you ran | Echo of the search term |
| Output `data.issues` | Slim summaries (`key`, `status`, `title`, `url`, `priority`) | Same shape; `url` is the Linear issue URL when available |

Agent mode returns **slim issue summaries** only. Call `items:show` for full description and attachments.

When writing automation for repos that may use either provider, treat `jql` as **“search query string”**: JQL for Jira, plain text for Linear.

## Saved views (`filters:list` / `filters:show`)

Both commands list and run **saved views** for the active provider. Names and semantics differ; CLI surface is shared.

### Jira — saved filters

Jira **filters** are saved JQL queries stored in your Jira account:

```bash
stud filters:list
stud fl
stud filters:show "My Team Open Bugs"
stud fs "My Team Open Bugs"
echo '{}' | stud filters:list --agent
echo '{"filterName":"My Team Open Bugs"}' | stud filters:show --agent
```

`filters:show` resolves the filter **by exact name** (then case-insensitive fallback) and runs the equivalent JQL `filter = "<name>"`.

### Linear — custom views

Linear **custom views** are saved issue lists (with underlying `filterData`) from your Linear workspace:

```bash
stud filters:list
stud filters:show "Active Bugs"
echo '{}' | stud filters:list --agent
echo '{"filterName":"Active Bugs"}' | stud filters:show --agent
```

- **List:** returns view names (and descriptions when present).
- **Show:** finds the view by name, executes its filter against Linear issues, returns matching work items.

View matching uses **exact name first**, then **case-insensitive** fallback (same as Jira filter names).

Raw Linear `filterData` is **not** exposed in agent output.

## Browse and inspect

```bash
stud items:list
stud ls -a
stud ls --project SCI
stud items:show SCI-123
stud sh SCI-123
stud sh ENG-42 --provider linear
```

| Aspect | Jira | Linear |
|--------|------|--------|
| `items:list` default scope | Assigned/active issues (JQL-backed) | Assigned/active issues (GraphQL filter) |
| `items:show` | Full issue + attachment metadata | Full issue + attachment metadata |
| `projects:list` | Jira projects | Linear teams (key + name) |

`items:show --agent` includes attachment metadata so automation can decide whether to download files.

## Create and update

```bash
stud items:create -p SCI -m "Add installer mode"
stud items:update SCI-123 --summary "New title"
stud iu SCI-123 --fields "labels=Bug,DX"
```

| Aspect | Jira | Linear |
|--------|------|--------|
| Description format | `--description-format markdown` converts to Jira ADF | Markdown stored as Linear description |
| Custom fields | Jira field names in `--fields` | Mapped via Linear field translator |
| Labels | Jira labels | Linear labels |

Use `stud projects:labels` to discover Linear label groups before configuring `linearTypeLabelGroupId` in project config.

## Attachments

```bash
stud items:download SCI-123 --path .cursor/tmp/SCI-123
stud items:upload SCI-123 -f report.md
stud items:download ENG-42 --provider linear --path .cursor/tmp/ENG-42
```

Both providers support upload and download when credentials and provider resolution succeed. Store downloads in task-specific temporary folders for automation.

## Transitions and start work

```bash
stud items:transition SCI-123
stud tx ENG-42 --provider linear
stud items:start SCI-123
stud start ENG-42
```

| Aspect | Jira | Linear |
|--------|------|--------|
| `items:transition` | Jira workflow transition | Linear workflow state update |
| `items:start` | Branch + optional Jira transition (`transitionId`) | Branch + optional Linear start state (`linearStartStateId`) |
| Branch prefix (Linear) | N/A | From `linearTypeBranchPrefixes` when configured |

When no key is provided, `stud` tries to detect the issue key from the current branch.

## Project metadata discovery

Use these before or during `stud config:project-init`:

```bash
stud projects:workflow --project SCI
stud projects:labels --project ENG --groups-only
```

| Command | Jira | Linear |
|---------|------|--------|
| `projects:workflow` | Lists workflow transitions for `transitionId` | Lists workflow states for `linearStartStateId` |
| `projects:labels` | Label metadata | Label groups and labels for type/prefix setup |

## Command summary

| Command | Jira | Linear |
|---------|------|--------|
| `items:search` | JQL | Plain search term |
| `filters:list` / `filters:show` | Saved filters | Custom views |
| `items:list` | JQL-backed list | GraphQL filter |
| `items:show` | Issue + attachments | Issue + attachments |
| `items:create` / `items:update` | Yes | Yes |
| `items:upload` / `items:download` | Yes | Yes |
| `items:transition` / `items:start` | Transitions | Workflow states |
| `projects:list` | Projects | Teams |
| `projects:workflow` | Transitions | States |
| `projects:labels` | Labels | Label groups |
