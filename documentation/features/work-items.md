# Work items — search and saved views

`stud items:search` and `stud filters:*` behave differently depending on which **work-item provider** is active for the repository (`workItemProvider` in `.git/stud.config`, or the single provider in global config).

**JQL is Jira-only.** When Linear is the active provider, `stud search` accepts a **plain search term**, not JQL.

See [Configuration](../setup/configuration.md) for provider setup and [Jira work items](jira-work-items.md) for Jira-specific create, update, attachment, and transition commands.

## Which provider runs?

| Config | Provider used |
|--------|----------------|
| `workItemProvider: jira` | Jira |
| `workItemProvider: linear` | Linear |
| `workItemProvider: auto` | Resolved from global `WORK_ITEM_PROVIDERS` and credentials |
| Global config lists one provider only | That provider |

Run `stud config:show --key workItemProvider` (or inspect `.git/stud.config`) when scripts must know which dialect to send.

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

```json
{
  "success": true,
  "data": {
    "issues": [
      {
        "key": "SCI-123",
        "status": "In Progress",
        "title": "Fix login redirect",
        "url": "https://linear.app/example/issue/SCI-123",
        "priority": "High"
      }
    ],
    "jql": "login bug"
  }
}
```

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

### Agent JSON (`filters:show --agent`)

| Field | Meaning |
|-------|---------|
| `filterName` | Saved Jira filter name or Linear custom view name |
| `issues` | Slim summaries (same fields as `items:search`) |

Raw Linear `filterData` is **not** exposed in agent output.

## Related commands

| Command | Jira | Linear |
|---------|------|--------|
| `items:list` | Assigned/active issues (JQL-backed) | Assigned/active issues (GraphQL filter) |
| `items:show` | Full issue + attachments | Full issue + attachments |
| `projects:list` | Jira projects | Linear teams |

Scope mapping (Jira project vs Linear team) is described in [ADR-022](../adr-022-jira-linear-work-item-scope-mapping.md).
